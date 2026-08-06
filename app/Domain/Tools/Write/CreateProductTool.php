<?php

namespace App\Domain\Tools\Write;

use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Puts a product in the catalogue, growing whatever it hangs from.
 *
 * A photographed typeplaatje names a brand, a kind and a model, and any of the
 * three can be new to this catalogue. Refusing until somebody first creates the
 * brand by hand would make the photo flow pointless, so the missing pieces are
 * created along with the product — and the preview says exactly which pieces
 * those are, because "maakt merk Mitsubishi Heavy aan" is precisely the kind of
 * thing someone wants to see before pressing the button.
 *
 * Everything is matched case-insensitively first. The catalogue already holds
 * near-duplicate brands typed by hand; this tool must not add "mitsubishi"
 * beside "Mitsubishi".
 */
class CreateProductTool implements Confirmable, Tool
{
    public static function name(): string
    {
        return 'create_product';
    }

    public function description(): string
    {
        return 'Zet een nieuw product in het assortiment, bijvoorbeeld vanaf een foto van het '
            . 'typeplaatje. Zoek eerst met find_products of het er al staat; bestaat het merk of '
            . 'de productsoort nog niet, dan worden die meteen mee aangemaakt en dat staat dan in '
            . 'het voorstel. Kenmerken (attributes) geef je als naam-waarde-paren, alleen voor wat '
            . 'je echt van het plaatje of uit documentatie hebt — verzin er geen. Er wordt nog '
            . 'niets vastgelegd: je krijgt terug dat er bevestiging nodig is en het systeem legt '
            . 'de gebruiker de knop voor.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'brand' => [
                    'type' => 'string',
                    'description' => 'De merknaam zoals op het apparaat staat, bijvoorbeeld "Mitsubishi Heavy".',
                ],
                'product_type' => [
                    'type' => 'string',
                    'description' => 'De soort, bijvoorbeeld "Airco binnendeel multisplit". Kies er een die al '
                        . 'bestaat als die de lading dekt; een nieuwe soort splitst het assortiment.',
                ],
                'model' => [
                    'type' => 'string',
                    'description' => 'De typeaanduiding, letterlijk overgenomen, bijvoorbeeld "SRK 25 ZS-WF".',
                ],
                'description' => [
                    'type' => 'string',
                    'description' => 'Korte omschrijving. Zet hier ook in wat het model onderscheidt, zoals '
                        . '"met ingebouwde wifi-module" — daar zoekt de assistent later op.',
                ],
                'part_no' => [
                    'type' => 'string',
                    'description' => 'Artikel- of onderdeelnummer, als dat op het plaatje staat.',
                ],
                'attributes' => [
                    'type' => 'object',
                    'description' => 'Kenmerken als naam-waarde-paren, bijvoorbeeld {"Koelvermogen": "2,5 kW"}. '
                        . 'Alleen wat je echt hebt afgelezen of opgezocht.',
                    'additionalProperties' => ['type' => 'string'],
                ],
            ],
            'required' => ['brand', 'product_type', 'model'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('create', Product::class);
    }

    /** Reading a typeplaatje and deciding what is new takes real judgement. */
    public static function difficulty(): int
    {
        return 7;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return ToolProfile::all();
    }

    public function previewOf(ToolCall $call): string
    {
        $model = trim((string) $call->stringArgument('model'));
        $attributes = $this->attributesIn($call);

        $pieces = ['Product aanmaken: ' . trim($call->stringArgument('brand') . ' ' . $model)];
        $pieces[] = $this->describePiece('merk', $call->stringArgument('brand'), $this->existingBrand($call->stringArgument('brand')));
        $pieces[] = $this->describePiece('soort', $call->stringArgument('product_type'), $this->existingType($call->stringArgument('product_type')));

        if ($attributes !== []) {
            $pieces[] = 'kenmerken: ' . collect($attributes)
                ->map(fn (string $value, string $name) => $name . ' = ' . $value)
                ->implode(', ');
        }

        return implode('; ', $pieces);
    }

    public function execute(ToolCall $call): ToolResult
    {
        $brand_name = trim((string) $call->stringArgument('brand'));
        $type_name = trim((string) $call->stringArgument('product_type'));
        $model = trim((string) $call->stringArgument('model'));

        if ($brand_name === '' || $type_name === '' || $model === '') {
            return ToolResult::failed('Geef merk, productsoort en model alle drie op.');
        }

        $existing_brand = $this->existingBrand($brand_name);

        /**
         * The same model of the same brand is the same product, and a second row
         * would give the assistant two answers to every later question about it.
         */
        if ($existing_brand !== null) {
            $duplicate = Product::query()
                ->where('brand_id', $existing_brand->id)
                ->whereRaw('LOWER(model) = ?', [mb_strtolower($model)])
                ->first();

            if ($duplicate !== null) {
                return ToolResult::failed(
                    'Dit product bestaat al: product #' . $duplicate->id . ' (' . $duplicate->display_name
                        . '). Gebruik dat nummer in plaats van een tweede aan te maken.'
                );
            }
        }

        $made = DB::transaction(function () use ($call, $brand_name, $type_name, $model, $existing_brand) {
            $brand = $existing_brand ?? Brand::create(['name' => $brand_name]);
            $type = $this->existingType($type_name) ?? ProductType::create(['name' => $type_name]);

            $product = Product::create([
                'brand_id' => $brand->id,
                'product_type_id' => $type->id,
                'model' => $model,
                'description' => $call->stringArgument('description'),
                'part_no' => $call->stringArgument('part_no'),
                'active' => true,
            ]);

            $attributes = [];

            foreach ($this->attributesIn($call) as $name => $value) {
                $attribute = ProductAttribute::query()
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first() ?? ProductAttribute::create(['name' => $name]);

                /** A kenmerk only shows on the form when its soort knows about it. */
                $attribute->productTypes()->syncWithoutDetaching([$type->id]);

                $attribute_value = $attribute->values()
                    ->whereRaw('LOWER(value) = ?', [mb_strtolower($value)])
                    ->first() ?? $attribute->values()->create(['value' => $value]);

                $product->productAttributeValueables()->create([
                    'product_attribute_id' => $attribute->id,
                    'product_attribute_value_id' => $attribute_value->id,
                ]);

                $attributes[$attribute->name] = $attribute_value->value;
            }

            return ['product' => $product, 'brand' => $brand, 'type' => $type, 'attributes' => $attributes];
        });

        return ToolResult::ok(
            [
                'product_id' => $made['product']->id,
                'brand_id' => $made['brand']->id,
                'brand' => $made['brand']->name,
                'brand_was_new' => $existing_brand === null,
                'product_type_id' => $made['type']->id,
                'product_type' => $made['type']->name,
                'model' => $model,
                'attributes' => $made['attributes'],
                'link' => '/products/' . $made['product']->id,
                'what' => $made['brand']->name . ' ' . $model,
                'note' => 'Het product staat in het assortiment. Hoort hier een machine bij een klant bij, '
                    . 'registreer die dan met create_asset en het serienummer van het plaatje.',
            ],
            'Product #' . $made['product']->id . ' (' . $made['brand']->name . ' ' . $model . ') aangemaakt.',
        );
    }

    /** @return array<string, string> */
    private function attributesIn(ToolCall $call): array
    {
        $given = $call->argument('attributes');

        if (!is_array($given)) {
            return [];
        }

        $attributes = [];

        foreach ($given as $name => $value) {
            $name = trim((string) $name);
            $value = trim((string) (is_scalar($value) ? $value : ''));

            if ($name !== '' && $value !== '') {
                $attributes[$name] = $value;
            }
        }

        return $attributes;
    }

    private function existingBrand(?string $name): ?Brand
    {
        return blank($name) ? null : Brand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();
    }

    private function existingType(?string $name): ?ProductType
    {
        return blank($name) ? null : ProductType::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();
    }

    /** One line per piece, saying outright whether pressing the button creates it. */
    private function describePiece(string $noun, ?string $name, ?object $existing): string
    {
        return $existing === null
            ? 'maakt ' . $noun . ' "' . trim((string) $name) . '" NIEUW aan'
            : $noun . ' ' . $existing->name . ' bestaat al';
    }
}

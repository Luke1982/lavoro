<?php

namespace App\Domain\Tools\Write;

use App\Actions\Products\FindOrAddProductAttributeValueAction;
use App\Actions\Products\SetProductAttributeValuesAction;
use App\Actions\Products\SyncProductAttributeTypesAction;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ResolvesBeforeConfirming;
use App\Domain\Tools\Write\Concerns\ValidatesLikeTheForm;
use App\Http\Requests\ProductTypeStoreRequest;
use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductType;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Puts a product in the catalogue, with a new brand when its brand is not there.
 *
 * A photographed typeplaatje names a brand, a kind and a model, and the brand
 * can be new to this catalogue. Refusing until somebody first creates it by hand
 * would make the photo flow pointless, so it is created along with the product —
 * and the preview says so, because "maakt merk Mitsubishi Heavy aan" is
 * precisely the kind of thing someone wants to see before pressing the button.
 * The soort is picked from the ones there are; only when none fits is a new one
 * made, under the existing soort it belongs to, and the preview names that one.
 *
 * Everything is matched case-insensitively first. The catalogue already holds
 * near-duplicate brands typed by hand; this tool must not add "mitsubishi"
 * beside "Mitsubishi".
 */
class CreateProductTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use ResolvesBeforeConfirming;
    use ValidatesLikeTheForm;

    private const CLOSEST_TYPES = 30;

    /** The arrows the type tree is written with; a slash or > belongs to names like "Koel/vriescel". */
    private const PATH_SEPARATOR = '/\s*(?:→|->)\s*/u';

    public static function name(): string
    {
        return 'create_product';
    }

    public function description(): string
    {
        return 'Zet een nieuw product in het assortiment, bijvoorbeeld vanaf een foto van het '
            . 'typeplaatje. Zoek eerst met find_products of het er al staat. Een nieuw merk wordt '
            . 'meteen mee aangemaakt. Kenmerken (attributes) geef je als naam-waarde-paren, alleen '
            . 'voor wat je echt van het plaatje of uit documentatie hebt — verzin er geen.';
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
                    'description' => 'Een bestaande soort, zo specifiek als past, bijvoorbeeld "Binnendeel '
                        . 'wandmodel". Bestaat hij niet, dan krijg je de lijst.',
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
                'new_product_type' => [
                    'type' => 'boolean',
                    'description' => 'Past geen bestaande soort: maak hem nieuw aan. Pad: "Bestaande soort → Nieuwe".',
                ],
                'attributes' => [
                    'type' => 'object',
                    'description' => 'Kenmerken als naam-waarde-paren, bijvoorbeeld {"Koelvermogen": "2,5 kW"}. '
                        . 'Alleen wat je echt hebt afgelezen of opgezocht, in de eenheid van het kenmerk.',
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
        $pieces[] = $this->describeType($this->resolution($call));

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
        $model = trim((string) $call->stringArgument('model'));

        if ($missing = $this->missingPieces($call)) {
            return $missing;
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

        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        foreach ($this->attributesIn($call) as $name => $input) {
            $attribute = $this->existingAttribute($name);

            if ($attribute !== null && $attribute->valueFields($input) === null) {
                return ToolResult::failed(
                    'Kenmerk ' . $attribute->name . ' is een getal'
                        . ($attribute->unit ? ' in ' . $attribute->unit : '')
                        . ', en "' . $input . '" is dat niet. Geef alleen het getal op, zoals 1,5.'
                );
            }
        }

        $made = DB::transaction(function () use ($call, $brand_name, $model, $existing_brand, $resolved) {
            $brand = $existing_brand ?? Brand::create(['name' => $brand_name]);
            $type = $resolved['type'] ?? ProductType::create([
                'name' => $resolved['new_type'],
                'parent_id' => $resolved['parent']?->id,
            ]);

            $product = Product::create([
                'brand_id' => $brand->id,
                'product_type_id' => $type->id,
                'model' => $model,
                'description' => $call->stringArgument('description'),
                'part_no' => $call->stringArgument('part_no'),
                'active' => true,
            ]);

            $attributes = [];
            $value_ids = [];

            foreach ($this->attributesIn($call) as $name => $input) {
                $attribute = $this->existingAttribute($name) ?? ProductAttribute::create(['name' => $name]);

                /** A kenmerk only shows on the form when its soort knows about it. */
                app(SyncProductAttributeTypesAction::class)->attach($attribute, $type->id);

                $fields = $attribute->valueFields($input);
                $value = app(FindOrAddProductAttributeValueAction::class)->handle($attribute, $fields);
                $value_ids[$attribute->id] = $value->id;
                $attributes[$attribute->name] = $value->value;
            }

            app(SetProductAttributeValuesAction::class)->handle($product, $value_ids);

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
                'product_type_was_new' => $resolved['type'] === null,
                'model' => $model,
                'attributes' => $made['attributes'],
                'link' => '/products/' . $made['product']->id,
                'what' => $made['brand']->name . ' ' . $model,
                'note' => 'Het product staat in het assortiment. Hoort hier een machine bij een klant bij die '
                    . 'niet via een taak op een werkbon komt, registreer die dan met create_asset en het '
                    . 'serienummer van het plaatje.',
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

    private function existingAttribute(string $name): ?ProductAttribute
    {
        return ProductAttribute::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();
    }

    private function existingBrand(?string $name): ?Brand
    {
        return blank($name) ? null : Brand::query()->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($name))])->first();
    }

    private function missingPieces(ToolCall $call): ?ToolResult
    {
        $blank = collect(['brand', 'product_type', 'model'])
            ->contains(fn (string $key) => trim((string) $call->stringArgument($key)) === '');

        return $blank ? ToolResult::failed('Geef merk, productsoort en model alle drie op.') : null;
    }

    /**
     * The soort the product goes under; or, with new_product_type, the soort to
     * make and the existing one it goes under; or why neither can be. The tree
     * is read once per call: the refusal, the preview and the work each ask.
     *
     * A soort that does not exist is refused once, with the ones that do. Left to
     * choose blind, the model filed a Tosot wall unit under a brand-new "Airco"
     * beside the Airconditioning tree in five runs out of six, and every product
     * filed there is missing from a price formula that filters on the real type.
     * A new one given as a path goes under the soort that path names, never in
     * as a root soort carrying the whole path as its name; a path naming a soort
     * that is not there is refused the same way.
     *
     * The ones that do are those most like what was asked, at most a few dozen
     * with the count of all of them: a catalogue of a thousand soorten does not
     * go back whole with every guess.
     *
     * @return ToolResult|array{type: ?ProductType, new_type: ?string, parent: ?ProductType, paths: array<int, string>}
     */
    private function resolve(ToolCall $call): ToolResult|array
    {
        if ($missing = $this->missingPieces($call)) {
            return $missing;
        }

        $asked = trim((string) $call->stringArgument('product_type'));
        $paths = ProductType::pathMap();
        $existing = $this->typeNamed($asked, $paths);

        if ($existing !== null) {
            return ['type' => $existing, 'new_type' => null, 'parent' => null, 'paths' => $paths];
        }

        if (!$call->booleanArgument('new_product_type')) {
            return $this->unknownType(
                'Productsoort "' . $asked . '" bestaat niet. Kies uit bestaande_soorten de soort die dit apparaat '
                    . 'dekt, zo specifiek mogelijk, en geef die naam of het hele pad op. ',
                $asked,
                $paths,
                'Past er echt geen, roep dan opnieuw aan met new_product_type: true.',
            );
        }

        $steps = array_values(array_filter(
            array_map('trim', preg_split(self::PATH_SEPARATOR, $asked)),
            fn (string $step) => $step !== '',
        ));
        $name = (string) array_pop($steps);
        $parent_path = implode(' → ', $steps);
        $parent = $steps === [] ? null : $this->typeNamed($parent_path, $paths);

        if ($steps !== [] && $parent === null) {
            return $this->unknownType(
                'De soort "' . $parent_path . '" waar "' . $name . '" onder moet, bestaat niet. Kies uit '
                    . 'bestaande_soorten de soort waar hij onder hoort en geef dat pad op met de nieuwe naam als '
                    . 'laatste stap. ',
                $parent_path,
                $paths,
                'Hoort hij nergens onder, geef dan alleen de naam: dan wordt het een hoofdsoort.',
            );
        }

        $twin = ProductType::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($twin !== null) {
            return ToolResult::failed('Soort "' . $name . '" bestaat al, als ' . $paths[$twin->id] . '. Een naam komt '
                . 'maar één keer voor in de boom: gebruik die soort, of kies een andere naam.');
        }

        $validator = $this->asTheFormWould(ProductTypeStoreRequest::class, ['name' => $name, 'parent_id' => $parent?->id], $call->user);

        if ($validator->fails()) {
            return ToolResult::failed($this->complaintOf($validator));
        }

        return ['type' => null, 'new_type' => $name, 'parent' => $parent, 'paths' => $paths];
    }

    /** @param array<int, string> $paths */
    private function unknownType(string $opening, string $asked, array $paths, string $closing): ToolResult
    {
        $closest = $this->closestPaths($asked, $paths);

        return ToolResult::failedWith(
            ['bestaande_soorten' => $closest, 'aantal_soorten' => count($paths)],
            $opening
                . (count($closest) < count($paths)
                    ? 'Dit zijn de ' . count($closest) . ' van de ' . count($paths) . ' soorten die er het meest op lijken. '
                    : '')
                . $closing,
        );
    }

    /**
     * By its own name, or by the path the list shows ("Airconditioning → Single
     * split → Binnendeel wandmodel"), however the arrows were typed. A name is
     * unique over the whole tree, so a bare name is never in two branches.
     *
     * @param  array<int, string>  $paths
     */
    private function typeNamed(string $name, array $paths): ?ProductType
    {
        $by_name = ProductType::query()->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])->first();

        if ($by_name !== null) {
            return $by_name;
        }

        $normalise = fn (string $path) => mb_strtolower((string) preg_replace(self::PATH_SEPARATOR, '→', trim($path)));
        $id = array_search($normalise($name), array_map($normalise, $paths), true);

        return $id === false ? null : ProductType::query()->find($id);
    }

    /**
     * The paths most like the name asked for: a path holding the name outright
     * first, then by how much of the name its own soort shares.
     *
     * @param  array<int, string>  $paths
     * @return array<int, string>
     */
    private function closestPaths(string $asked, array $paths): array
    {
        $asked = mb_strtolower($asked);

        $scores = array_map(function (string $path) use ($asked) {
            $own = mb_strtolower((string) last(explode(' → ', $path)));
            similar_text($asked, $own, $percent);

            return (str_contains(mb_strtolower($path), $asked) ? 100 : 0) + $percent;
        }, $paths);

        arsort($scores);

        return array_values(array_map(
            fn (int $id) => $paths[$id],
            array_slice(array_keys($scores), 0, self::CLOSEST_TYPES),
        ));
    }

    /**
     * The soort's line: the existing one, or the new one with the soort it goes
     * under, so a wrong branch is seen before the button rather than after.
     *
     * @param  ToolResult|array<string, mixed>  $resolved
     */
    private function describeType(ToolResult|array $resolved): string
    {
        if ($resolved instanceof ToolResult) {
            return (string) $resolved->summary;
        }

        if ($resolved['type'] !== null) {
            return 'soort ' . $resolved['paths'][$resolved['type']->id] . ' bestaat al';
        }

        return 'maakt soort "' . $resolved['new_type'] . '" NIEUW aan, '
            . ($resolved['parent'] === null
                ? 'als hoofdsoort (nergens onder)'
                : 'onder ' . $resolved['paths'][$resolved['parent']->id]);
    }

    /** One line per piece, saying outright whether pressing the button creates it. */
    private function describePiece(string $noun, ?string $name, ?object $existing): string
    {
        return $existing === null
            ? 'maakt ' . $noun . ' "' . trim((string) $name) . '" NIEUW aan'
            : $noun . ' ' . $existing->name . ' bestaat al';
    }
}

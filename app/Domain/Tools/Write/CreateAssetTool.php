<?php

namespace App\Domain\Tools\Write;

use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\Product;
use App\Models\User;

/**
 * Registers a machine at a customer, serial number and all.
 *
 * The photo flow ends here: typeplaatje read, product found or freshly made,
 * and then the actual machine on the wall gets its row — with the serial the
 * plate shows, at the customer whose wall it is.
 */
class CreateAssetTool implements Confirmable, Tool
{
    public static function name(): string
    {
        return 'create_asset';
    }

    public function description(): string
    {
        return 'Registreert een machine bij een klant, met serienummer. Het product moet al in het '
            . 'assortiment staan — zoek het met find_products, of maak het eerst met create_product '
            . 'als het er niet is. Neem het serienummer letterlijk over van het typeplaatje. Er '
            . 'wordt nog niets vastgelegd: je krijgt terug dat er bevestiging nodig is en het '
            . 'systeem legt de gebruiker de knop voor.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'De klant waar de machine hangt.',
                ],
                'product_id' => [
                    'type' => 'integer',
                    'description' => 'Het product uit het assortiment. Eerst opzoeken of aanmaken.',
                ],
                'serial_number' => [
                    'type' => 'string',
                    'description' => 'Het serienummer, letterlijk van het typeplaatje.',
                ],
                'location_id' => [
                    'type' => 'integer',
                    'description' => 'De locatie van de klant waar de machine hangt, als die bekend is. '
                        . 'Moet een locatie van deze klant zijn.',
                ],
            ],
            'required' => ['customer_id', 'product_id'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('create', Asset::class);
    }

    /** The pieces are already found by now; this is filling them in. */
    public static function difficulty(): int
    {
        return 5;
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
        $product = Product::find($call->integerArgument('product_id'));
        $customer = Customer::find($call->integerArgument('customer_id'));
        $location = $call->integerArgument('location_id') === null
            ? null
            : Location::where('id', $call->integerArgument('location_id'))
                ->when($customer !== null, fn ($q) => $q->where('customer_id', $customer->id))
                ->first();

        $serial = $call->stringArgument('serial_number');

        return 'Machine registreren: ' . ($product?->display_name ?? 'onbekend product')
            . (blank($serial) ? ' zonder serienummer' : ' met serienummer ' . $serial)
            . ' bij ' . ($customer?->name ?? 'onbekende klant')
            . ($location ? ' op ' . $location->addressLine() : '');
    }

    public function execute(ToolCall $call): ToolResult
    {
        $customer = Customer::find($call->integerArgument('customer_id'));

        if ($customer === null) {
            return ToolResult::notFound('Klant #' . $call->integerArgument('customer_id'));
        }

        $product = Product::find($call->integerArgument('product_id'));

        if ($product === null) {
            return ToolResult::failed(
                'Product #' . $call->integerArgument('product_id') . ' staat niet in het assortiment. '
                    . 'Zoek het met find_products of maak het eerst aan met create_product.'
            );
        }

        $serial = trim((string) $call->stringArgument('serial_number'));

        /**
         * The same serial twice is almost always the same machine photographed
         * twice, and the second row splits its history in two.
         */
        if ($serial !== '') {
            $twin = Asset::query()
                ->whereRaw('LOWER(serial_number) = ?', [mb_strtolower($serial)])
                ->first();

            if ($twin !== null) {
                return ToolResult::failed(
                    'Er bestaat al een machine met serienummer ' . $serial . ': machine #' . $twin->id
                        . ' bij klant #' . $twin->customer_id . '. Controleer of dit dezelfde machine is '
                        . 'voordat je een tweede registreert.'
                );
            }
        }

        $location_id = $call->integerArgument('location_id');

        if ($location_id !== null
            && !Location::where('id', $location_id)->where('customer_id', $customer->id)->exists()) {
            return ToolResult::failed('Die locatie hoort niet bij deze klant.');
        }

        $asset = Asset::create([
            'customer_id' => $customer->id,
            'product_id' => $product->id,
            'serial_number' => $serial ?: null,
            'location_id' => $location_id,
            'status' => 'Actief',
        ]);

        return ToolResult::ok(
            [
                'asset_id' => $asset->id,
                'customer_id' => $customer->id,
                'customer' => $customer->name,
                'product_id' => $product->id,
                'serial_number' => $asset->serial_number,
                'link' => '/assets/' . $asset->id,
                'what' => ($product->display_name ?? 'machine') . ($serial !== '' ? ' (' . $serial . ')' : ''),
            ],
            'Machine #' . $asset->id . ' geregistreerd bij ' . $customer->name . '.',
        );
    }
}

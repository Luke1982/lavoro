<?php

namespace App\Domain\Tools\Write;

use App\Actions\Locations\CreateLocationAction;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ResolvesBeforeConfirming;
use App\Domain\Tools\Write\Concerns\ValidatesLikeTheForm;
use App\Http\Requests\LocationStoreRequest;
use App\Models\Customer;
use App\Models\Location;
use App\Models\User;
use App\Support\AddressFormatter;
use App\Support\PostalCode;

/**
 * Adds a location to a customer: the place the work is actually done.
 *
 * A quote is addressed to the head office and signed there, while the machines
 * hang in a branch across town. That branch is a location, and without it every
 * werkbon and appointment from the quote would send the mechanic to the office.
 */
class CreateLocationTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use ResolvesBeforeConfirming;
    use ValidatesLikeTheForm;

    public static function name(): string
    {
        return 'create_location';
    }

    public function description(): string
    {
        return 'Voegt een locatie toe aan een klant: een adres waar het werk gebeurt dat niet het klantadres '
            . 'is, zoals een filiaal. find_customer geeft de bestaande locaties; maak er geen tweede van aan. '
            . 'Roep deze tool aan zodra je klant en adres hebt: er wordt nog niets aangemaakt, het systeem '
            . 'legt de gebruiker een bevestigingsknop voor.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'De klant waar de locatie bij hoort.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Naam van de locatie, bijvoorbeeld "Filiaal Utrecht" of "Magazijn".',
                ],
                'address' => [
                    'type' => 'string',
                    'description' => 'Straat en huisnummer.',
                ],
                'postal_code' => [
                    'type' => 'string',
                    'description' => 'Postcode, bijvoorbeeld 1234AB.',
                ],
                'city' => [
                    'type' => 'string',
                    'description' => 'Plaats.',
                ],
                'country' => [
                    'type' => 'string',
                    'description' => 'Land. Laat weg voor Nederland.',
                ],
                'location_code' => [
                    'type' => 'string',
                    'description' => 'Eigen code van de klant voor deze locatie, alleen als die op het document staat.',
                ],
            ],
            'required' => ['customer_id', 'title', 'address'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('create', Location::class);
    }

    /** Klant is al gevonden; dit is een adres overnemen. */
    public static function difficulty(): int
    {
        return 4;
    }

    public function requiresConfirmation(): bool
    {
        return true;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function previewOf(ToolCall $call): string
    {
        $shown = LocationStoreRequest::normalize($this->dataOf($call));

        $customer = Customer::find($call->integerArgument('customer_id'));
        $address = AddressFormatter::format(
            $shown['address'] ?? null,
            $shown['postal_code'] ?? null,
            $shown['city'] ?? null,
        );

        return 'Locatie aanmaken bij ' . ($customer?->name ?? 'onbekende klant')
            . ': ' . ($shown['title'] ?? 'zonder naam')
            . ($address ? ', ' . $address : '')
            . (empty($shown['country']) ? '' : ', ' . $shown['country'])
            . (empty($shown['location_code']) ? '' : ' (code ' . $shown['location_code'] . ')');
    }

    /**
     * The location as it would be stored, or why not: a customer that does not
     * exist, what the form would refuse, a location already there.
     *
     * @return ToolResult|array<string, mixed>
     */
    private function resolve(ToolCall $call): ToolResult|array
    {
        $customer_id = $call->integerArgument('customer_id');
        $customer = $customer_id === null ? null : Customer::find($customer_id);

        if ($customer === null) {
            return ToolResult::notFound('Klant #' . ($customer_id ?? '?'));
        }

        $validator = $this->asTheFormWould(LocationStoreRequest::class, $this->dataOf($call));

        if ($validator->fails()) {
            return ToolResult::failed($this->complaintOf($validator));
        }

        $attributes = LocationStoreRequest::normalize($validator->validated());
        $twin = $this->twinOf($attributes);

        if ($twin === null) {
            return $attributes;
        }

        return ToolResult::failedWith(
            [
                'error' => 'Deze locatie bestaat al bij ' . $customer->name
                    . ': locatie #' . $twin->id . ' (' . $twin->title . ').',
                'location_id' => $twin->id,
                'title' => $twin->title,
                'address' => $twin->addressLine(),
                'link' => '/locations/' . $twin->id,
                'note' => 'Er is niets aangemaakt. Gebruik deze locatie. Zijn het echt twee locaties op '
                    . 'hetzelfde adres, dan maakt de gebruiker de tweede aan op het scherm.',
            ],
            'Locatie bestaat al: #' . $twin->id . ' ' . $twin->title . '.',
        );
    }

    public function execute(ToolCall $call): ToolResult
    {
        $attributes = $this->resolve($call);

        if ($attributes instanceof ToolResult) {
            return $attributes;
        }

        $location = app(CreateLocationAction::class)->execute($attributes);
        $customer = $location->customer;

        return ToolResult::ok(
            [
                'location_id' => $location->id,
                'customer_id' => $customer->id,
                'customer' => $customer->name,
                'title' => $location->title,
                'address' => $location->addressLine(),
                'link' => '/locations/' . $location->id,
                'what' => $location->title . ' (' . $location->addressLine() . ')',
            ],
            'Locatie #' . $location->id . ' (' . $location->title . ') aangemaakt bij ' . $customer->name . '.',
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function dataOf(ToolCall $call): array
    {
        return array_filter([
            'customer_id' => $call->integerArgument('customer_id'),
            'title' => $call->stringArgument('title'),
            'address' => $call->stringArgument('address'),
            'postal_code' => $call->stringArgument('postal_code'),
            'city' => $call->stringArgument('city'),
            'country' => $call->stringArgument('country'),
            'location_code' => $call->stringArgument('location_code'),
        ], fn (int|string|null $value) => $value !== null && $value !== '');
    }

    /**
     * The customer's own location at this address, if it already has one.
     *
     * The form leaves this to the person, who sees the list of locations right
     * above it. A model sees it only if it looked, and a second location at the
     * same address halves the history of the machines standing there.
     *
     * @param  array<string, mixed>  $attributes  Already normalised.
     */
    private function twinOf(array $attributes): ?Location
    {
        $address = mb_strtolower(trim((string) ($attributes['address'] ?? '')));

        if (empty($attributes['customer_id']) || $address === '') {
            return null;
        }

        return Location::query()
            ->where('customer_id', $attributes['customer_id'])
            ->whereRaw('LOWER(TRIM(address)) = ?', [$address])
            ->when(
                !empty($attributes['postal_code']),
                fn ($query) => PostalCode::whereSame($query, $attributes['postal_code'])
            )
            ->first();
    }
}

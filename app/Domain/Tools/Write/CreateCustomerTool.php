<?php

namespace App\Domain\Tools\Write;

use App\Actions\Customers\CreateCustomerAction;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ResolvesBeforeConfirming;
use App\Domain\Tools\Write\Concerns\ValidatesLikeTheForm;
use App\Http\Requests\CustomerStoreRequest;
use App\Models\Customer;
use App\Models\User;
use App\Support\AddressFormatter;
use App\Support\PostalCode;
use Illuminate\Support\Collection;

/**
 * Adds a customer, typically one read off a signed quote.
 *
 * The same rules and the same clean-up as the form, so a customer made here is
 * indistinguishable from one typed in by hand. What the form does not do, and
 * this does, is refuse a customer that plainly already exists: a person typing
 * a name sees the list filter as they type, a model reading a letterhead does
 * not, and a second "Bakkerij Jansen" splits every machine and werkbon between
 * two records that both look right.
 */
class CreateCustomerTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use ResolvesBeforeConfirming;
    use ValidatesLikeTheForm;

    public static function name(): string
    {
        return 'create_customer';
    }

    public function description(): string
    {
        return 'Maakt een nieuwe klant aan. Zoek eerst met find_customer op naam en plaats: bestaat hij al, '
            . 'gebruik dan die. Neem naam en adres letterlijk over; verzin niets dat niet op het document '
            . 'staat. Roep deze tool aan zodra je de gegevens hebt: er wordt nog niets aangemaakt, het '
            . 'systeem legt de gebruiker een bevestigingsknop voor.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'name' => [
                    'type' => 'string',
                    'description' => 'De naam van de klant: het bedrijf, of de persoon als het een particulier is.',
                ],
                'contactname' => [
                    'type' => 'string',
                    'description' => 'De contactpersoon bij een bedrijf, bijvoorbeeld wie de offerte tekende.',
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
                'email' => [
                    'type' => 'string',
                    'description' => 'E-mailadres.',
                ],
                'phone' => [
                    'type' => 'string',
                    'description' => 'Telefoonnummer, tien cijfers.',
                ],
            ],
            'required' => ['name'],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('create', Customer::class);
    }

    /** Overnemen wat op het document staat; het opzoeken is het werk van find_customer. */
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
        $shown = CustomerStoreRequest::normalize($this->dataOf($call));

        $address = AddressFormatter::format(
            $shown['address'] ?? null,
            $shown['postal_code'] ?? null,
            $shown['city'] ?? null,
        );

        $contact = array_filter([
            $shown['contactname'] ?? null,
            $shown['email'] ?? null,
            $shown['phone'] ?? null,
        ]);

        return 'Klant aanmaken: ' . ($shown['name'] ?? 'zonder naam')
            . ($address ? ', ' . $address : '')
            . (empty($shown['country']) ? '' : ', ' . $shown['country'])
            . ($contact ? ' (' . implode(', ', $contact) . ')' : '');
    }

    public function execute(ToolCall $call): ToolResult
    {
        $attributes = $this->resolve($call);

        if ($attributes instanceof ToolResult) {
            return $attributes;
        }

        $customer = app(CreateCustomerAction::class)->execute($attributes);
        $address = AddressFormatter::format($customer->address, $customer->postal_code, $customer->city);

        return ToolResult::ok(
            [
                'customer_id' => $customer->id,
                'name' => $customer->name,
                'address' => $address,
                'link' => '/customers/' . $customer->id,
                'what' => $customer->name . ($address ? ' (' . $address . ')' : ''),
            ],
            'Klant #' . $customer->id . ' (' . $customer->name . ') aangemaakt.',
        );
    }

    /**
     * The customer as it would be stored. What the form would refuse, and a
     * customer that already exists, both go back to the model before anybody
     * is shown a button: either way it cannot be carried out as it stands.
     *
     * @return ToolResult|array<string, mixed>
     */
    private function resolve(ToolCall $call): ToolResult|array
    {
        $validator = $this->asTheFormWould(CustomerStoreRequest::class, $this->dataOf($call));

        if ($validator->fails()) {
            return ToolResult::failed($this->complaintOf($validator));
        }

        $attributes = CustomerStoreRequest::normalize($validator->validated());
        $twins = $this->twinsOf($attributes);

        return $twins->isEmpty() ? $attributes : $this->refuseTwins($twins);
    }

    /**
     * Only what was actually given: an empty string is the model saying nothing,
     * and the form's own middleware turns those into null too.
     *
     * @return array<string, string>
     */
    private function dataOf(ToolCall $call): array
    {
        return array_filter([
            'name' => $call->stringArgument('name'),
            'contactname' => $call->stringArgument('contactname'),
            'address' => $call->stringArgument('address'),
            'postal_code' => $call->stringArgument('postal_code'),
            'city' => $call->stringArgument('city'),
            'country' => $call->stringArgument('country'),
            'email' => $call->stringArgument('email'),
            'phone' => $call->stringArgument('phone'),
        ], fn (?string $value) => $value !== null && $value !== '');
    }

    /**
     * Customers this one would be a second copy of: the same name, or the same
     * address at the same postal code. Either alone is enough — a company keeps
     * its name when it moves, and an address keeps its building when the
     * company on the letterhead changes its trading name.
     *
     * @param  array<string, mixed>  $attributes  Already normalised.
     * @return Collection<int, Customer>
     */
    private function twinsOf(array $attributes): Collection
    {
        $name = mb_strtolower(trim((string) ($attributes['name'] ?? '')));
        $address = mb_strtolower(trim((string) ($attributes['address'] ?? '')));
        $postal_code = (string) ($attributes['postal_code'] ?? '');

        if ($name === '' && ($address === '' || $postal_code === '')) {
            return collect();
        }

        return Customer::query()
            ->where(function ($query) use ($name, $address, $postal_code) {
                if ($name !== '') {
                    $query->orWhereRaw('LOWER(TRIM(name)) = ?', [$name]);
                }

                if ($address !== '' && $postal_code !== '') {
                    $query->orWhere(fn ($same_place) => PostalCode::whereSame($same_place, $postal_code)
                        ->whereRaw('LOWER(TRIM(address)) = ?', [$address]));
                }
            })
            ->orderBy('id')
            ->limit(5)
            ->get(['id', 'name', 'address', 'postal_code', 'city']);
    }

    /**
     * @param  Collection<int, Customer>  $twins
     */
    private function refuseTwins(Collection $twins): ToolResult
    {
        $said = 'Deze klant bestaat al: ' . $this->namesOf($twins) . '.';

        return ToolResult::failedWith(
            [
                'error' => $said,
                'existing_customers' => $twins->map(fn (Customer $twin) => [
                    'customer_id' => $twin->id,
                    'name' => $twin->name,
                    'address' => AddressFormatter::format($twin->address, $twin->postal_code, $twin->city),
                    'link' => '/customers/' . $twin->id,
                ])->values()->all(),
                'note' => 'Er is niets aangemaakt. Gebruik de bestaande klant. Weet je niet zeker of het '
                    . 'dezelfde is, vraag het dan aan de gebruiker in plaats van er een tweede aan te maken.',
            ],
            $said,
        );
    }

    /**
     * @param  Collection<int, Customer>  $customers
     */
    private function namesOf(Collection $customers): string
    {
        return $customers->map(fn (Customer $customer) => '#' . $customer->id . ' ' . $customer->name)->implode(', ');
    }
}

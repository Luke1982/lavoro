<?php

namespace App\Domain\Tools\Write;

use App\Actions\Contracts\CreateMaintenanceContractAction;
use App\Domain\Contracts\ContractLabels;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ChecksEnums;
use App\Domain\Tools\Write\Concerns\ResolvesBeforeConfirming;
use App\Domain\Tools\Write\Concerns\ValidatesLikeTheForm;
use App\Enums\ContractInterval;
use App\Http\Requests\MaintenanceContractStoreRequest;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\MaintenanceContract;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * Draws up a maintenance contract for a customer, with the machines it covers.
 *
 * Usually the last step after a signed quote: the customer and the machines
 * exist by now, and the quote says what is serviced how often and for what
 * price. The machines go on in the same breath as the contract, so the timeline
 * and the work order generator never see a contract that covers nothing.
 *
 * Always contract-wide frequency, and never generating work orders on its own
 * unless asked: both are settings people change afterwards on the contract
 * screen, and a model guessing them is a planning nobody decided on.
 */
class CreateMaintenanceContractTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use ChecksEnums;
    use ResolvesBeforeConfirming;
    use ValidatesLikeTheForm;

    public static function name(): string
    {
        return 'create_maintenance_contract';
    }

    public function description(): string
    {
        return 'Maakt een onderhoudscontract aan voor een klant, met de machines die eronder vallen; die '
            . 'moeten van deze klant zijn. Neem prijs, interval en frequentie over van het document en '
            . 'verzin ze niet: staat er iets niet in, vraag het dan. Roep deze tool aan zodra je alles hebt: '
            . 'er wordt nog niets aangemaakt, het systeem legt de gebruiker een bevestigingsknop voor.';
    }

    public function inputSchema(): array
    {
        $intervals = implode(', ', array_map(fn (ContractInterval $case) => $case->value, ContractInterval::cases()));

        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => [
                    'type' => 'integer',
                    'description' => 'De klant van het contract.',
                ],
                'title' => [
                    'type' => 'string',
                    'description' => 'Naam van het contract. Laat weg als het document er geen geeft.',
                ],
                'start_date' => [
                    'type' => 'string',
                    'description' => 'Ingangsdatum als JJJJ-MM-DD.',
                ],
                'end_date' => [
                    'type' => 'string',
                    'description' => 'Einddatum als JJJJ-MM-DD. Laat weg voor een contract zonder einddatum.',
                ],
                'price' => [
                    'type' => 'number',
                    'description' => 'Prijs per prijsinterval in euro\'s, exclusief btw. 0 mag, bijvoorbeeld '
                        . 'als het eerste jaar bij de offerte inbegrepen is.',
                ],
                'price_interval' => [
                    'type' => 'string',
                    'description' => 'Hoe vaak de prijs gerekend wordt. Een van: ' . $intervals . '.',
                ],
                'price_interval_days' => [
                    'type' => 'integer',
                    'description' => 'Alleen bij price_interval "' . ContractInterval::aangepast->value
                        . '": om de hoeveel dagen.',
                ],
                'frequency' => [
                    'type' => 'string',
                    'description' => 'Hoe vaak de machines onderhoud krijgen. Een van: ' . $intervals . '.',
                ],
                'frequency_days' => [
                    'type' => 'integer',
                    'description' => 'Alleen bij frequency "' . ContractInterval::aangepast->value
                        . '": om de hoeveel dagen.',
                ],
                'asset_ids' => [
                    'type' => 'array',
                    'items' => ['type' => 'integer'],
                    'description' => 'De machines die onder het contract vallen.',
                ],
                'auto_generate' => [
                    'type' => 'boolean',
                    'description' => 'Laat het contract zelf werkbonnen aanmaken volgens de frequentie. Alleen '
                        . 'true als de gebruiker daar uitdrukkelijk om vraagt.',
                ],
            ],
            'required' => ['customer_id', 'start_date', 'price', 'price_interval', 'frequency'],
            'additionalProperties' => false,
        ];
    }

    /**
     * The contract screen asks two questions for this: whether somebody may make
     * a contract, and separately whether they may put machines on one. A
     * contract arriving with its machines needs both answers, and one that makes
     * its own werkbonnen needs the right to create werkbonnen too.
     */
    public function authorize(User $user, array $arguments): bool
    {
        if (!$user->can('create', MaintenanceContract::class)) {
            return false;
        }

        if (
            filter_var($arguments['auto_generate'] ?? false, FILTER_VALIDATE_BOOLEAN)
            && !$user->can('autoGenerate', MaintenanceContract::class)
        ) {
            return false;
        }

        return empty($arguments['asset_ids']) || $user->can('attachAsset', new MaintenanceContract);
    }

    /** Prijs, interval, frequentie en looptijd uit een offerte halen en de machines erbij zoeken. */
    public static function difficulty(): int
    {
        return 6;
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
        $customer = Customer::find($call->integerArgument('customer_id'));
        $data = $this->dataOf($call);
        $machines = count($call->integerListArgument('asset_ids'));
        $start = $call->dateArgument('start_date');
        $end = $call->dateArgument('end_date');

        return 'Onderhoudscontract aanmaken'
            . (blank($data['title'] ?? null) ? '' : ' "' . $data['title'] . '"')
            . ' voor ' . ($customer?->name ?? 'onbekende klant')
            . ($start ? ', vanaf ' . $start->format('d-m-Y') : '')
            . ($end ? ' t/m ' . $end->format('d-m-Y') : '')
            . ', ' . $this->priceLine($data)
            . ', onderhoud ' . $this->intervalLine($data, 'frequency')
            . ', ' . $machines . ' machine(s)'
            . (($data['auto_generate'] ?? false) ? ', maakt zelf werkbonnen aan' : '');
    }

    /**
     * The contract, its customer and its machines, or why not. Everything this
     * tool would refuse is known before anybody is asked, so it goes back to the
     * model instead of becoming a button that fails on the click. An unreadable
     * value is named first: the form would only call it missing, which sends
     * the model looking for a value it already gave.
     *
     * @return ToolResult|array{customer: Customer, assets: Collection<int, Asset>, attributes: array<string, mixed>}
     */
    private function resolve(ToolCall $call): ToolResult|array
    {
        $customer_id = $call->integerArgument('customer_id');
        $customer = $customer_id === null ? null : Customer::find($customer_id);

        if ($customer === null) {
            return ToolResult::notFound('Klant #' . ($customer_id ?? '?'));
        }

        $unreadable = $this->unreadableIn($call);

        if ($unreadable !== null) {
            return ToolResult::failed($unreadable);
        }

        $validator = $this->asTheFormWould(MaintenanceContractStoreRequest::class, $this->dataOf($call));

        if ($validator->fails()) {
            return ToolResult::failed($this->complaintOf($validator));
        }

        $assets = $this->assetsOf($call);
        $problem = $this->assetProblem($call, $customer, $assets);

        return $problem === null
            ? ['customer' => $customer, 'assets' => $assets, 'attributes' => $validator->validated()]
            : ToolResult::failed($problem);
    }

    public function execute(ToolCall $call): ToolResult
    {
        $resolved = $this->resolve($call);

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        ['customer' => $customer, 'assets' => $assets, 'attributes' => $attributes] = $resolved;

        $contract = app(CreateMaintenanceContractAction::class)->execute($attributes, $assets);

        return ToolResult::ok(
            [
                'maintenance_contract_id' => $contract->id,
                'customer_id' => $customer->id,
                'customer' => $customer->name,
                'title' => $contract->display_title,
                'start_date' => $contract->start_date?->toDateString(),
                'end_date' => $contract->end_date?->toDateString(),
                'price' => (float) $contract->price,
                'price_interval' => $contract->price_interval?->value,
                'frequency' => $contract->frequency?->value,
                'auto_generate' => (bool) $contract->auto_generate,
                'assets' => $assets->map(fn (Asset $asset) => [
                    'asset_id' => $asset->id,
                    'label' => ContractLabels::asset($asset),
                ])->values()->all(),
                'link' => '/maintenancecontracts/' . $contract->id,
                'what' => $contract->display_title . ' (' . $assets->count() . ' machine(s))',
            ],
            'Onderhoudscontract #' . $contract->id . ' aangemaakt voor ' . $customer->name
                . ' met ' . $assets->count() . ' machine(s).',
        );
    }

    /**
     * Only what this person can already see; anything else counts as not found.
     *
     * @return Collection<int, Asset>
     */
    private function assetsOf(ToolCall $call): Collection
    {
        return Asset::visibleTo($call->user)
            ->whereIn('id', $call->integerListArgument('asset_ids'))
            ->with('product.brand')
            ->get();
    }

    /**
     * Whether these are this customer's own machines, by the rule the contract
     * screen applies: the machine itself stands at the customer. A contract
     * covering somebody else's machine bills one customer for servicing another
     * one's; a part of a set has no customer of its own and goes on a contract
     * through its set, the way the screen only ever lets it.
     *
     * @param  Collection<int, Asset>  $assets
     */
    private function assetProblem(ToolCall $call, Customer $customer, Collection $assets): ?string
    {
        $asset_ids = $call->integerListArgument('asset_ids');

        $missing = array_diff($asset_ids, $assets->pluck('id')->all());

        if ($missing !== []) {
            return 'Machine(s) ' . implode(', ', $missing) . ' niet gevonden.';
        }

        $strays = $assets->reject(fn (Asset $asset) => $asset->customer_id === $customer->id);

        return $strays->isEmpty()
            ? null
            : 'Deze machines staan niet bij ' . $customer->name . ': '
                . $strays->map(fn (Asset $asset) => ContractLabels::asset($asset))->implode(', ') . '.';
    }

    /**
     * The contract as the form would send it.
     *
     * Intervals are matched case-insensitively against the real ones, because
     * "jaarlijks" means "Jaarlijks" and nothing else. Days only travel with
     * "Aangepast (dagen)", as on the form, which only shows the field then.
     *
     * @return array<string, mixed>
     */
    private function dataOf(ToolCall $call): array
    {
        $price_interval = $this->interval($call->stringArgument('price_interval'));
        $frequency = $this->interval($call->stringArgument('frequency'));
        $custom = ContractInterval::aangepast->value;

        $data = [
            'customer_id' => $call->integerArgument('customer_id'),
            'title' => blank($call->stringArgument('title')) ? null : $call->stringArgument('title'),
            'start_date' => $call->dateArgument('start_date')?->toDateString(),
            'end_date' => $call->dateArgument('end_date')?->toDateString(),
            'price' => $call->argument('price'),
            'price_interval' => $price_interval,
            'price_interval_days' => $price_interval === $custom ? $call->integerArgument('price_interval_days') : null,
            'manage_frequency_per_asset' => false,
            'frequency' => $frequency,
            'frequency_days' => $frequency === $custom ? $call->integerArgument('frequency_days') : null,
            'auto_generate' => filter_var($call->argument('auto_generate', false), FILTER_VALIDATE_BOOLEAN),
        ];

        return array_filter($data, fn ($value) => $value !== null);
    }

    /** Something given that cannot be read at all, said as such. */
    private function unreadableIn(ToolCall $call): ?string
    {
        foreach (['start_date' => 'startdatum', 'end_date' => 'einddatum'] as $key => $label) {
            if ($call->wasGiven($key) && $call->dateArgument($key) === null) {
                return 'De ' . $label . ' "' . $call->stringArgument($key) . '" is geen datum. '
                    . 'Geef hem als JJJJ-MM-DD.';
            }
        }

        foreach (['price_interval' => 'prijsinterval', 'frequency' => 'frequentie'] as $key => $label) {
            if ($call->wasGiven($key) && $this->interval($call->stringArgument($key)) === null) {
                return '"' . $call->stringArgument($key) . '" is geen geldige ' . $label . '. Kies uit: '
                    . $this->valuesOf(ContractInterval::class) . '.';
            }
        }

        return null;
    }

    private function interval(?string $value): ?string
    {
        return blank($value) ? null : $this->oneOf($value, ContractInterval::class, '');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function priceLine(array $data): string
    {
        $price = is_numeric($data['price'] ?? null) ? (float) $data['price'] : null;

        return ($price === null ? 'prijs onbekend' : "\u{20AC}" . Money::human((int) round($price * 100)))
            . ' ' . $this->intervalLine($data, 'price_interval');
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function intervalLine(array $data, string $key): string
    {
        return mb_strtolower(ContractLabels::frequency($data[$key] ?? null, $data[$key . '_days'] ?? null));
    }
}

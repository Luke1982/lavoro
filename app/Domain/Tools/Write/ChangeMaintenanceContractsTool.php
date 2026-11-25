<?php

namespace App\Domain\Tools\Write;

use App\Actions\Contracts\RecalculateContractPriceAction;
use App\Actions\Contracts\UpdateMaintenanceContractAction;
use App\Domain\Contracts\ContractLabels;
use App\Domain\Pricing\PricedMachines;
use App\Domain\Signals\Signals;
use App\Domain\Tools\ChecksBeforeConfirming;
use App\Domain\Tools\Confirmable;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ResolvesBeforeConfirming;
use App\Domain\Tools\Write\Concerns\ValidatesLikeTheForm;
use App\Http\Requests\MaintenanceContractUpdateRequest;
use App\Models\MaintenanceContract;
use App\Models\User;
use App\Support\BulkSelection;
use App\Support\Counted;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Extends maintenance contracts and changes their price, one contract or an
 * indexation over many, through the same update the contract screen saves.
 *
 * Nobody agrees to a price unseen: the confirmation names every contract with
 * its price before and after. A contract priced by a formula gets no hand-set
 * price, as on its screen; it is named in the confirmation with the reason it
 * is left alone. Extending such a contract can change what its formula comes
 * to, the periodic price and the one-time price alike, and both are shown as
 * RecalculateContractPriceAction will store them.
 *
 * A selection is bounded the way every bulk action on a list is, and runs as
 * one batch: all of it is saved, or none of it.
 */
class ChangeMaintenanceContractsTool implements ChecksBeforeConfirming, Confirmable, Tool
{
    use ResolvesBeforeConfirming;
    use ValidatesLikeTheForm;

    public static function name(): string
    {
        return 'change_maintenance_contracts';
    }

    public function description(): string
    {
        return 'Verlengt onderhoudscontracten, wijzigt of indexeert (procenten) de prijs. '
            . 'Met prijsformule geen handmatige prijs. Bevestigen toont oud en nieuw.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'contract_ids' => ['type' => 'array', 'items' => ['type' => 'integer'], 'description' => 'Contracten.'],
                'all_running' => ['type' => 'boolean', 'description' => 'Alle niet afgelopen.'],
                'customer_id' => ['type' => 'integer', 'description' => 'Alleen deze klant (all_running).'],
                'ends_until' => ['type' => 'string', 'description' => 'Einde uiterlijk JJJJ-MM-DD (all_running).'],
                'end_date' => ['type' => 'string', 'description' => 'Nieuwe einddatum JJJJ-MM-DD.'],
                'extend_months' => ['type' => 'integer', 'description' => 'Maanden erbij.'],
                'price' => ['type' => 'number', 'description' => 'Prijs per periode, excl. btw.'],
                'increase_percent' => ['type' => 'number', 'description' => 'Indexatie in %, bv. 4.'],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    /**
     * The right to change contracts, and for a price the right to see prices,
     * which the contract screen asks before it shows the field at all. Changing
     * a contract is a permission rather than a matter of which contract, so it
     * is asked once, as the list's bulk actions ask it, however many are named.
     */
    public function authorize(User $user, array $arguments): bool
    {
        $call = new ToolCall(self::name(), $arguments, $user);

        return $user->can('update', new MaintenanceContract)
            && (!$this->changesPrice($call) || $user->can('seeFinancials', MaintenanceContract::class));
    }

    /** Een selectie, een datum of maanden, en een prijs of percentage uit een zin halen. */
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

    /**
     * Every contract with what changes on it, and every one left alone with why.
     *
     * A proposal that cannot be done is refused before any button is shown, so
     * the refusal's own words only end up here on the click, when the contracts
     * have moved since; the executor then says why it will not run.
     */
    public function previewOf(ToolCall $call): string
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return $resolved->summary ?? 'Deze wijziging kan niet worden uitgevoerd.';
        }

        $lines = array_map(
            fn (array $plan) => $this->labelOf($plan['contract']) . ': ' . implode('; ', $plan['lines']),
            $resolved['plans'],
        );

        $changing = count(array_filter($resolved['plans'], fn (array $plan) => $plan['attributes'] !== []));

        return 'Onderhoudscontracten wijzigen (' . Counted::of($changing, 'contract', 'contracten') . '):'
            . "\n- " . implode("\n- ", $lines)
            . ($resolved['annual'] === null ? '' : "\nSamen per jaar: " . Money::euros($resolved['annual'][0])
                . ' → ' . Money::euros($resolved['annual'][1]) . '.');
    }

    /**
     * Carries out the resolution the preview was written from: the executor
     * has just compared that preview with the approved one on this same call.
     */
    public function execute(ToolCall $call): ToolResult
    {
        $resolved = $this->resolution($call);

        if ($resolved instanceof ToolResult) {
            return $resolved;
        }

        $plans = array_values(array_filter($resolved['plans'], fn (array $plan) => $plan['attributes'] !== []));
        $update = app(UpdateMaintenanceContractAction::class);

        Signals::asBatch(fn () => DB::transaction(function () use ($plans, $update) {
            foreach ($plans as $plan) {
                $update->execute($plan['contract'], $plan['attributes']);
            }
        }));

        $financials = $call->user->can('seeFinancials', MaintenanceContract::class);

        /** The update and the repricing it sets off both write to these same models. */
        $changed = array_map(fn (array $plan) => [
            'maintenance_contract_id' => $plan['contract']->id,
            'title' => $plan['contract']->display_title,
            'end_date' => $plan['contract']->end_date?->toDateString(),
            'price' => $financials ? (float) $plan['contract']->price : null,
            'link' => '/maintenancecontracts/' . $plan['contract']->id,
        ], $plans);

        $skipped = array_values(array_map(
            fn (array $plan) => $this->labelOf($plan['contract']) . ': ' . $plan['skipped'],
            array_filter($resolved['plans'], fn (array $plan) => $plan['skipped'] !== null),
        ));

        return ToolResult::ok(
            ['changed' => $changed, 'skipped' => $skipped],
            Counted::of(count($changed), 'onderhoudscontract', 'onderhoudscontracten') . ' gewijzigd'
                . ($skipped === [] ? '' : ', ' . count($skipped) . ' overgeslagen')
                . '. Elke wijziging staat op de tijdlijn van het contract.',
        );
    }

    /**
     * The contracts, what changes on each and why some are left alone, or the
     * reason none of it can be done. Read through resolution(), which works it
     * out once per call.
     *
     * @return ToolResult|array{plans: array<int, array{contract: MaintenanceContract, attributes: array<string, mixed>, lines: array<int, string>, skipped: ?string}>, annual: ?array{0: float, 1: float}}
     */
    private function resolve(ToolCall $call): ToolResult|array
    {
        $complaint = $this->complaintAboutArguments($call);

        if ($complaint !== null) {
            return ToolResult::failed($complaint);
        }

        $contracts = $this->contractsOf($call);

        if ($contracts instanceof ToolResult) {
            return $contracts;
        }

        PricedMachines::preload($contracts->filter(fn (MaintenanceContract $contract) => $contract->price_formula_version_id !== null));

        $financials = $call->user->can('seeFinancials', MaintenanceContract::class);
        $recalculate = app(RecalculateContractPriceAction::class);
        $plans = [];
        $annual = [0.0, 0.0];

        foreach ($contracts as $contract) {
            $plan = $this->planFor($call, $contract, $financials, $recalculate);

            if ($plan instanceof ToolResult) {
                return $plan;
            }

            $plans[] = $plan;
            $annual[0] += $plan['annual'][0];
            $annual[1] += $plan['annual'][1];
        }

        $changing = array_filter($plans, fn (array $plan) => $plan['attributes'] !== []);

        if ($changing === []) {
            return ToolResult::failed('Er verandert niets aan deze contracten. '
                . implode(' ', array_map(fn (array $plan) => $this->labelOf($plan['contract']) . ': '
                    . ($plan['skipped'] ?? 'blijft gelijk') . '.', $plans)));
        }

        return [
            'plans' => array_map(fn (array $plan) => Arr::except($plan, 'annual'), $plans),
            'annual' => $this->changesPrice($call) && count($plans) > 1 ? $annual : null,
        ];
    }

    private function changesPrice(ToolCall $call): bool
    {
        return $call->wasGiven('price') || $call->wasGiven('increase_percent');
    }

    /** What the arguments themselves get wrong, before any contract is looked at. */
    private function complaintAboutArguments(ToolCall $call): ?string
    {
        $all_running = $call->booleanArgument('all_running');
        $invalid_date = $call->invalidDateAmong('ends_until', 'end_date');

        return match (true) {
            $all_running && $call->wasGiven('contract_ids') => 'Geef contract_ids of all_running, niet allebei.',
            !$all_running && ($call->wasGiven('customer_id') || $call->wasGiven('ends_until')) => 'customer_id en ends_until beperken all_running; gebruik ze alleen samen daarmee.',
            $invalid_date !== null => $invalid_date,
            !$all_running && $call->integerListArgument('contract_ids') === [] => 'Zeg welke contracten: contract_ids, of all_running voor alle lopende.',
            $call->wasGiven('end_date') && $call->wasGiven('extend_months') => 'Geef een nieuwe einddatum of een aantal maanden verlenging, niet allebei.',
            $call->wasGiven('price') && $call->wasGiven('increase_percent') => 'Geef een nieuwe prijs of een percentage, niet allebei.',
            $call->wasGiven('extend_months') && ($call->integerArgument('extend_months') ?? 0) < 1 => 'Verlengen gaat met minstens één maand.',
            $call->wasGiven('price') && !is_numeric($call->argument('price')) => 'De prijs "' . $call->stringArgument('price') . '" is geen bedrag.',
            $call->wasGiven('increase_percent') && (!is_numeric($call->argument('increase_percent')) || (float) $call->argument('increase_percent') === 0.0) => 'Geef de indexatie als een percentage anders dan 0, bijvoorbeeld 4.',
            !$call->wasGiven('end_date') && !$call->wasGiven('extend_months') && !$this->changesPrice($call) => 'Zeg wat er verandert: end_date, extend_months, price of increase_percent.',
            default => null,
        };
    }

    /**
     * The contracts named, or every one that has not ended, of one customer or
     * ending by a date when asked; never more than a bulk action on the
     * contract list could take at once. Counted before any is loaded, so a
     * selection too large is refused without reading it.
     *
     * @return ToolResult|Collection<int, MaintenanceContract>
     */
    private function contractsOf(ToolCall $call): ToolResult|Collection
    {
        $all_running = $call->booleanArgument('all_running');
        $ids = $call->integerListArgument('contract_ids');

        $customer_id = $call->integerArgument('customer_id');
        $ends_until = $call->dateArgument('ends_until');

        $query = $all_running
            ? MaintenanceContract::query()
                ->notEnded()
                ->when($customer_id, fn ($query) => $query->where('customer_id', $customer_id))
                ->when($ends_until, fn ($query) => $query->whereNotNull('end_date')->whereDate('end_date', '<=', $ends_until->toDateString()))
            : MaintenanceContract::query()->whereKey($ids);

        $count = (clone $query)->count();

        if ($count > BulkSelection::LIMIT) {
            return ToolResult::failed(
                BulkSelection::tooMany($count, 'contracten', 'wijzigen', $all_running ? 'gevraagd' : 'geselecteerd')
                    . ($all_running ? ' Bijvoorbeeld per klant met customer_id, of met ends_until.' : '')
            );
        }

        $contracts = $query
            ->with([
                'customer:id,name',
                'priceFormulaVersion:id,price_formula_id,number,name,graph',
                'priceFormulaVersion.formula:id,name',
            ])
            ->orderBy('id')
            ->get();

        $missing = array_diff($ids, $contracts->modelKeys());

        return match (true) {
            $missing !== [] => ToolResult::notFound((count($missing) === 1 ? 'Contract ' : 'Contracten ') . implode(', ', $missing)),
            $contracts->isEmpty() => ToolResult::failed($customer_id || $ends_until
                ? 'Er zijn geen lopende contracten die hierbij passen.'
                : 'Er zijn geen lopende contracten.'),
            default => $contracts,
        };
    }

    /**
     * One contract's change, checked by the rules of its own edit form.
     *
     * @return ToolResult|array{contract: MaintenanceContract, attributes: array<string, mixed>, lines: array<int, string>, skipped: ?string, annual: array{0: float, 1: float}}
     */
    private function planFor(
        ToolCall $call,
        MaintenanceContract $contract,
        bool $financials,
        RecalculateContractPriceAction $recalculate,
    ): ToolResult|array {
        $attributes = [];
        $lines = [];
        $skipped = [];
        $old_price = Money::cents($contract->getRawOriginal('price'));
        $old_cents = $old_price ?? 0;
        $new_cents = $old_cents;
        $cancelled = $contract->cancelled_at !== null ? ' (geannuleerd)' : '';

        $end_date = $this->newEndDate($call, $contract);

        if ($call->wasGiven('extend_months') && $contract->end_date === null) {
            $skipped[] = 'niet verlengd: het heeft geen einddatum';
        } elseif ($end_date !== null && $this->wouldShorten($call, $contract, $end_date)) {
            $skipped[] = $contract->end_date === null
                ? 'einddatum niet gewijzigd: het loopt door zonder einddatum'
                : 'einddatum niet gewijzigd: het loopt al tot ' . $contract->end_date->format('d-m-Y');
        } elseif ($end_date !== null && $end_date->toDateString() === $contract->end_date?->toDateString()) {
            $lines[] = 'einddatum blijft ' . $end_date->format('d-m-Y');
        } elseif ($end_date !== null) {
            $attributes['end_date'] = $end_date->toDateString();
            $lines[] = 'einddatum ' . ($contract->end_date?->format('d-m-Y') ?? 'geen') . ' → ' . $end_date->format('d-m-Y')
                . ($cancelled === '' ? '' : ' (blijft geannuleerd)');
        }

        if ($this->changesPrice($call)) {
            if ($contract->price_formula_version_id !== null) {
                $skipped[] = 'prijs niet gewijzigd: die volgt ' . $this->formulaOf($contract)
                    . '; pas de formule aan of koppel hem los op het contract';
            } elseif ($old_price === null && !$call->wasGiven('price')) {
                $skipped[] = 'niet geïndexeerd: het heeft geen prijs';
            } else {
                $new_cents = $call->wasGiven('price')
                    ? Money::cents($call->argument('price')) ?? 0
                    : (int) round($old_cents * (1 + ((float) $call->argument('increase_percent')) / 100));

                if ($new_cents !== $old_price) {
                    $attributes['price'] = $new_cents / 100;
                }

                $lines[] = $new_cents === $old_price
                    ? 'prijs blijft ' . Money::euros($old_cents / 100) . $cancelled
                    : 'prijs ' . $this->eurosOrNone($old_price) . ' → ' . Money::euros($new_cents / 100) . ' '
                        . $this->intervalOf($contract) . $cancelled;
            }
        }

        if (isset($attributes['end_date']) && $contract->price_formula_version_id !== null) {
            $repriced = $this->formulaLinesWithEndDate($contract, $end_date, $financials, $recalculate);
            $new_cents = $repriced['price'] ?? $new_cents;
            $lines = array_merge($lines, $repriced['lines']);
        }

        if ($attributes !== []) {
            $validator = $this->asTheFormWould(
                MaintenanceContractUpdateRequest::class,
                $attributes + ['start_date' => $contract->start_date?->toDateString()],
                $call->user,
                ['maintenancecontract' => $contract],
            );

            if ($validator->fails()) {
                return ToolResult::failed($this->labelOf($contract) . ': ' . $this->complaintOf($validator));
            }

            $attributes = Arr::only($validator->validated(), array_keys($attributes));
        }

        $lines = array_merge($lines, $skipped);

        return [
            'contract' => $contract,
            'attributes' => $attributes,
            'lines' => $lines === [] ? ['blijft gelijk'] : $lines,
            'skipped' => $skipped === [] ? null : implode('; ', $skipped),
            'annual' => [$contract->annualPrice($old_cents / 100), $contract->annualPrice($new_cents / 100)],
        ];
    }

    /**
     * What the contract's formula comes to once the end date has moved, the
     * periodic and the one-time price, asked of the very rule that stores them
     * on the save, which also decides whether that is a change at all. A price
     * that stays as it is gets no line.
     *
     * @return array{price: ?int, lines: array<int, string>}
     */
    private function formulaLinesWithEndDate(
        MaintenanceContract $contract,
        CarbonImmutable $end_date,
        bool $financials,
        RecalculateContractPriceAction $recalculate,
    ): array {
        $extended = clone $contract;
        $extended->end_date = $end_date;

        $prices = $recalculate->changedPricesFor($extended);

        if ($prices === null) {
            return ['price' => null, 'lines' => []];
        }

        $old_cents = Money::cents($contract->getRawOriginal('price')) ?? 0;
        $old_one_time = Money::cents($contract->getRawOriginal('one_time_price'));

        if (!$financials) {
            return ['price' => $prices['price'], 'lines' => ['de prijs wordt opnieuw berekend met de prijsformule']];
        }

        $lines = [];

        if ($prices['price'] !== $old_cents) {
            $lines[] = 'prijs volgens ' . $this->formulaOf($contract) . ' ' . Money::euros($old_cents / 100) . ' → '
                . Money::euros($prices['price'] / 100) . ' ' . $this->intervalOf($contract);
        }

        if ($prices['one_time'] !== $old_one_time) {
            $lines[] = 'eenmalige kosten ' . $this->eurosOrNone($old_one_time) . ' → ' . $this->eurosOrNone($prices['one_time']);
        }

        return ['price' => $prices['price'], 'lines' => $lines];
    }

    private function newEndDate(ToolCall $call, MaintenanceContract $contract): ?CarbonImmutable
    {
        if ($date = $call->dateArgument('end_date')) {
            return $date;
        }

        $months = $call->integerArgument('extend_months');

        return $months === null || $contract->end_date === null
            ? null
            : CarbonImmutable::parse($contract->end_date)->addMonthsNoOverflow($months);
    }

    /**
     * A fixed end date over every running contract is meant to extend them.
     * One that already runs longer, or without an end date at all, would be
     * shortened by it without anybody having said so, and is left alone; named
     * by id, a contract gets the date it was given.
     */
    private function wouldShorten(ToolCall $call, MaintenanceContract $contract, CarbonImmutable $end_date): bool
    {
        if (!$call->wasGiven('end_date') || !$call->booleanArgument('all_running')) {
            return false;
        }

        return $contract->end_date === null || $contract->end_date->toDateString() > $end_date->toDateString();
    }

    private function eurosOrNone(?int $cents): string
    {
        return $cents === null ? 'geen' : Money::euros($cents / 100);
    }

    private function labelOf(MaintenanceContract $contract): string
    {
        return '#' . $contract->id . ' ' . $contract->display_title;
    }

    private function formulaOf(MaintenanceContract $contract): string
    {
        return 'prijsformule ' . ($contract->priceFormulaVersion?->displayName() ?? 'onbekend');
    }

    private function intervalOf(MaintenanceContract $contract): string
    {
        return '(' . mb_strtolower(ContractLabels::frequency($contract->price_interval?->value, $contract->price_interval_days)) . ')';
    }
}

<?php

namespace App\Domain\Tools\Read;

use App\Domain\Contracts\ContractLabels;
use App\Domain\Planning\Clock;
use App\Domain\Tools\Read\Concerns\ReportsTheWholeCount;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Models\Asset;
use App\Models\MaintenanceContract;
use App\Models\ProductType;
use App\Models\User;
use App\Services\MaintenanceContractServiceOrderGenerator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Lists maintenance contracts by customer, status, end date, next beurt or
 * price formula, with what each costs a year for whoever may see prices.
 *
 * The status means what the contract's own status attribute means, so the
 * answer agrees with the contract list. The next beurt is the first day a
 * machine on it is due, by the werkbon generator's own rule; whether a werkbon
 * is then made by itself is auto_generate, and a contract without it is due all
 * the same. The totals are worked out over every matching contract, not only
 * the ones that fit in the answer, so "what do our contracts bring in a year"
 * is never a sum of a slice.
 */
class FindMaintenanceContractsTool implements Tool
{
    use ReportsTheWholeCount;

    private const STATUSES = ['actief', 'toekomstig', 'verlopen', 'geannuleerd'];

    public static function name(): string
    {
        return 'find_maintenance_contracts';
    }

    public function description(): string
    {
        return 'Zoekt onderhoudscontracten op klant, status, einddatum, beurt of formule, '
            . 'met jaarbedrag en totalen over alle treffers. Formuleopbouw: try_price_formula.';
    }

    public function inputSchema(): array
    {
        $date = fn (string $description) => ['type' => 'string', 'description' => $description . ' JJJJ-MM-DD.'];

        return [
            'type' => 'object',
            'properties' => [
                'customer_id' => ['type' => 'integer', 'description' => 'Klant.'],
                'status' => ['type' => 'string', 'enum' => self::STATUSES, 'description' => 'Status.'],
                'ends_from' => $date('Einddatum vanaf'),
                'ends_until' => $date('Einddatum t/m'),
                'service_due_within_days' => [
                    'type' => 'integer',
                    'description' => 'Beurt binnen N dagen; te laat telt mee.',
                ],
                'price_formula_id' => ['type' => 'integer', 'description' => 'Prijsformule.'],
                'product_type' => [
                    'type' => 'string',
                    'description' => 'Met machines van dit type of subtype, bv. airco.',
                ],
            ],
            'required' => [],
            'additionalProperties' => false,
        ];
    }

    public function authorize(User $user, array $arguments): bool
    {
        if (!$user->can('viewAny', MaintenanceContract::class)) {
            return false;
        }

        return empty($arguments['price_formula_id']) || $user->can('seeFinancials', MaintenanceContract::class);
    }

    /** Een paar filters uit een zin halen; de datums rekent de tool zelf. */
    public static function difficulty(): int
    {
        return 3;
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function execute(ToolCall $call): ToolResult
    {
        if ($complaint = $call->invalidDateAmong('ends_from', 'ends_until')) {
            return ToolResult::failed($complaint);
        }

        $status = $call->stringArgument('status');

        if (!blank($status) && !in_array($status, self::STATUSES, true)) {
            return ToolResult::failed('Onbekende status "' . $status . '". Kies uit: ' . implode(', ', self::STATUSES) . '.');
        }

        $type_ids = $this->productTypeIdsOf($call);

        if ($type_ids === []) {
            return ToolResult::failed('Geen producttype gevonden dat "' . $call->stringArgument('product_type') . '" heet.');
        }

        $limit = (int) config('assistant.max_results', 25);
        $financials = $call->user->can('seeFinancials', MaintenanceContract::class);
        $due_within = $call->integerArgument('service_due_within_days');
        $generator = app(MaintenanceContractServiceOrderGenerator::class);

        if ($due_within === null) {
            $total = $this->matching($call, $status, $type_ids)->count();
            $shown = $this->sortedQuery($call, $this->withWhatRowsShow($this->matching($call, $status, $type_ids)))
                ->limit($limit)
                ->get();
            $next_services = $this->nextServicesOf($shown, $generator);
            $active_query = $this->matching($call, $status, $type_ids)->active();
            $totals = [
                'active' => (clone $active_query)->count(),
                'annual_total_active' => $financials ? $this->annualTotalOf($active_query) : null,
            ];
        } else {
            [$contracts, $next_services] = $this->dueWithin($call, $status, $type_ids, $due_within, $generator);
            $contracts = $contracts->sortBy(fn (MaintenanceContract $contract) => $next_services[$contract->id])->values();
            $total = $contracts->count();
            $shown = $contracts->take($limit);
            $active = $contracts->filter(fn (MaintenanceContract $contract) => $contract->status === 'actief');
            $totals = [
                'active' => $active->count(),
                'annual_total_active' => $financials
                    ? round($active->sum(fn (MaintenanceContract $contract) => $contract->annualPrice()), 2)
                    : null,
            ];
        }

        $rows = $shown->map(fn (MaintenanceContract $contract) => [
            'maintenance_contract_id' => $contract->id,
            'title' => $contract->display_title,
            'customer' => $contract->customer?->name,
            'customer_id' => $contract->customer_id,
            'status' => $contract->status,
            'start_date' => $contract->start_date?->toDateString(),
            'end_date' => $contract->end_date?->toDateString(),
            'price' => $financials ? (float) $contract->price : null,
            'price_interval' => $financials
                ? ContractLabels::frequency($contract->price_interval?->value, $contract->price_interval_days)
                : null,
            'annual_price' => $financials ? $contract->annualPrice() : null,
            'one_time_price' => $financials && $contract->one_time_price !== null ? (float) $contract->one_time_price : null,
            'price_formula' => $financials ? $contract->priceFormulaVersion?->displayName() : null,
            'next_service' => $next_services[$contract->id] ?? null,
            'auto_generate' => (bool) $contract->auto_generate,
            'machines_of_type' => $type_ids === null ? null : $contract->assets
                ->filter(fn (Asset $asset) => in_array($asset->product?->product_type_id, $type_ids, true))
                ->count(),
            'link' => '/maintenancecontracts/' . $contract->id,
        ])->values()->all();

        $content = [
            'contracts' => $rows,
            'totals' => $totals,
        ];

        if ($financials) {
            $content['note'] = 'annual_price is de prijs maal het aantal perioden per jaar; annual_total_active telt '
                . 'alleen de actieve contracten van alle treffers.';
        }

        return $this->answerWithTotal($content, count($rows), $total, 'contracten');
    }

    /** What a row shows besides the contract itself, and what its next beurt is worked out from. */
    private function withWhatRowsShow(Builder $query): Builder
    {
        return $query->with([
            'customer:id,name',
            'priceFormulaVersion:id,price_formula_id,number,name',
            'priceFormulaVersion.formula:id,name',
            'assets:assets.id,assets.product_id',
            'assets.product:id,product_type_id',
        ]);
    }

    /**
     * The contracts with a beurt within the days asked, and that beurt per
     * contract. The next beurt is the generator's rule and cannot be asked of
     * the database, so every match is read, a chunk at a time, and only those
     * due are kept.
     *
     * @param  ?array<int, int>  $type_ids
     * @return array{0: Collection<int, MaintenanceContract>, 1: Collection<int, ?string>}
     */
    private function dueWithin(
        ToolCall $call,
        ?string $status,
        ?array $type_ids,
        int $due_within,
        MaintenanceContractServiceOrderGenerator $generator,
    ): array {
        $horizon = Clock::todayAsDate()->addDays($due_within)->toDateString();
        $due = collect();
        $next_services = collect();

        $this->withWhatRowsShow($this->matching($call, $status, $type_ids))
            ->chunkById(200, function (Collection $chunk) use ($generator, $horizon, $due, $next_services) {
                foreach ($chunk as $contract) {
                    $next = $this->nextServiceOf($contract, $generator);

                    if ($next !== null && $next <= $horizon) {
                        $due->push($contract);
                        $next_services->put($contract->id, $next);
                    }
                }
            });

        return [$due, $next_services];
    }

    /**
     * What these contracts come to a year together, in one query: how many
     * there are per price and price interval, each group's price per year
     * worked out by the rule annualPrice() applies.
     */
    private function annualTotalOf(Builder $contracts): float
    {
        $groups = $contracts
            ->toBase()
            ->select('price', 'price_interval', 'price_interval_days')
            ->selectRaw('count(*) as contracts')
            ->groupBy('price', 'price_interval', 'price_interval_days')
            ->get();

        $total = $groups->sum(fn (object $group) => $group->contracts * MaintenanceContract::annualAmount(
            (float) $group->price,
            $group->price_interval,
            $group->price_interval_days === null ? null : (int) $group->price_interval_days,
        ));

        return round($total, 2);
    }

    /**
     * @param  Collection<int, MaintenanceContract>  $contracts
     * @return Collection<int, ?string>
     */
    private function nextServicesOf(Collection $contracts, MaintenanceContractServiceOrderGenerator $generator): Collection
    {
        return $contracts->mapWithKeys(fn (MaintenanceContract $contract) => [
            $contract->id => $this->nextServiceOf($contract, $generator),
        ]);
    }

    /**
     * Everything but the next beurt, narrowed down in the query. The status by
     * the scopes that mirror the status attribute, so it means exactly what it
     * says on the contract without loading every contract to read it off.
     *
     * @param  ?array<int, int>  $type_ids  The product types a machine on it must have, or null for any.
     */
    private function matching(ToolCall $call, ?string $status, ?array $type_ids): Builder
    {
        $query = MaintenanceContract::query();

        if (!blank($status)) {
            $query->withStatus($status);
        }

        if ($customer_id = $call->integerArgument('customer_id')) {
            $query->where('customer_id', $customer_id);
        }

        if ($from = $call->dateArgument('ends_from')) {
            $query->where('end_date', '>=', $from->toDateString());
        }

        if ($until = $call->dateArgument('ends_until')) {
            $query->where('end_date', '<=', $until->toDateString());
        }

        if ($formula_id = $call->integerArgument('price_formula_id')) {
            $query->whereHas('priceFormulaVersion', fn (Builder $version) => $version->where('price_formula_id', $formula_id));
        }

        if ($type_ids !== null) {
            $query->whereHas('assets.product', fn (Builder $product) => $product->whereIn('product_type_id', $type_ids));
        }

        return $query;
    }

    /**
     * The product types named and every type under them, as the price formula's
     * type filter counts them: a machine of a subtype is a machine of its parent
     * type. Null when no type was asked for, empty when none is called that.
     *
     * @return ?array<int, int>
     */
    private function productTypeIdsOf(ToolCall $call): ?array
    {
        if (blank($call->stringArgument('product_type'))) {
            return null;
        }

        return ProductType::withDescendants(ProductType::query()
            ->where('name', 'like', $call->likeArgument('product_type'))
            ->pluck('id')
            ->all());
    }

    /**
     * Soonest end first when end dates were asked, and otherwise newest first,
     * as on the contract list. A beurt can only be sorted on once it is worked
     * out, which dueWithin() does.
     */
    private function sortedQuery(ToolCall $call, Builder $query): Builder
    {
        if ($call->wasGiven('ends_from') || $call->wasGiven('ends_until')) {
            return $query->orderBy('end_date')->orderBy('id');
        }

        return $query->orderByDesc('start_date')->orderByDesc('id');
    }

    /**
     * The first day a machine on the contract is due, by the generator's own
     * rule (nextDueFor): the machine's effective frequency on from its last
     * generated werkbon, or the contract's start when none was made. Due is not
     * made: a contract without auto_generate gets no werkbon by itself. An ended
     * contract, one without machines, or a beurt past the end date has none.
     */
    private function nextServiceOf(MaintenanceContract $contract, MaintenanceContractServiceOrderGenerator $generator): ?string
    {
        if ($contract->hasEnded()) {
            return null;
        }

        $next = $contract->assets
            ->map(fn (Asset $asset) => $generator->nextDueFor($contract, $asset->pivot)?->toDateString())
            ->filter()
            ->sort()
            ->first();

        if ($next === null || ($contract->end_date && $next > $contract->end_date->toDateString())) {
            return null;
        }

        return $next;
    }
}

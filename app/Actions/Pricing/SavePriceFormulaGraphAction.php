<?php

namespace App\Actions\Pricing;

use App\Domain\Pricing\CompactFormula;
use App\Domain\Pricing\ContractPricing;
use App\Domain\Pricing\MovedContracts;
use App\Domain\Pricing\PricedMachines;
use App\Domain\Signals\Pricing\PriceFormulaSaved;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
use App\Models\MaintenanceContract;
use App\Models\PriceFormula;
use App\Models\PriceFormulaVersion;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Saves the graph of a price formula. A version no contract uses yet is simply
 * overwritten; once a contract is on it, saving makes the next version instead,
 * so that contract keeps the price it was agreed on until somebody moves it.
 * Only what decides a price counts as a change: blocks moved on the canvas or
 * given another title make no new version.
 *
 * Saved from the editor, it is told which version the editor opened, so two
 * people working on one formula never lose each other's work without a word.
 *
 * Whether the running contracts come along to a new version is the person's
 * call, asked when they save: left alone they keep their version and price.
 */
class SavePriceFormulaGraphAction
{
    public function __construct(
        private readonly CompactFormula $compact,
        private readonly UpgradeContractsToLatestVersionAction $upgrade,
        private readonly ContractPricing $pricing,
        private readonly RenamePriceFormulaVersionAction $rename,
    ) {}

    /**
     * @param  int|null  $based_on  the version number the graph was edited from; a newer one saved in the meantime refuses
     * @param  string|null  $version_name  a name for the version saved, such as a year; null leaves the name as it is, a blank one takes it away
     * @param  bool  $move_contracts  a new version takes every running contract on this formula along
     * @return array{version: PriceFormulaVersion, is_new_version: bool, behind: int, moved: MovedContracts}
     */
    public function execute(
        PriceFormula $formula,
        array $graph,
        ?User $user,
        ?int $based_on = null,
        ?string $version_name = null,
        bool $move_contracts = false,
    ): array {
        return DB::transaction(function () use ($formula, $graph, $user, $based_on, $version_name, $move_contracts) {
            PriceFormula::whereKey($formula->id)->lockForUpdate()->first();
            $latest = $formula->versions()->first();

            if ($based_on !== null && $latest !== null && $latest->number !== $based_on) {
                throw new Refusal(
                    'Deze formule is intussen opgeslagen als versie ' . $latest->number . ', in een ander venster '
                    . 'of door iemand anders. Laad de pagina opnieuw om die versie te zien. Je eigen wijzigingen '
                    . 'gaan daarbij verloren, dus schrijf ze eerst op.'
                );
            }

            $previous = $latest ? $this->compact->summary($latest->graph) : null;
            $summary = $this->compact->summary($graph);
            $prices_changed = $latest === null || self::pricing($latest->graph) !== self::pricing($graph);

            if ($latest !== null && !self::outgrows($latest, $graph)) {
                $latest->update(['graph' => $graph, 'created_by' => $user?->id]);
                $version = $latest;
                $is_new = false;

                if ($version_name !== null) {
                    $this->rename->execute($version, $version_name);
                }
            } else {
                $version = $formula->versions()->create([
                    'number' => ($latest?->number ?? 0) + 1,
                    'name' => $version_name,
                    'graph' => $graph,
                    'created_by' => $user?->id,
                ]);
                $is_new = $latest !== null;
            }

            if ($previous !== $summary || $prices_changed) {
                Signals::dispatch(new PriceFormulaSaved($formula, $version->number, $is_new, $previous, $summary, $version->name));
            }

            $moved = $is_new && $move_contracts ? $this->upgrade->execute($formula) : MovedContracts::none();

            return [
                'version' => $version,
                'is_new_version' => $is_new,
                'behind' => $formula->contractsBehind()->notEnded()->count(),
                'moved' => $moved,
            ];
        });
    }

    /**
     * What saving this graph would do, asked before saving: whether it makes a
     * new version, how many running contracts are on the formula, and what they
     * cost per period now and would cost on that new version. Without prices,
     * for whoever may not see them, nothing is worked out.
     *
     * @return array{new_version: bool, number: int|null, running: int, now: float|null, then: float|null}
     */
    public function impact(PriceFormula $formula, array $graph, bool $with_prices = true): array
    {
        $latest = $formula->versions()->first();
        $new_version = $latest !== null && self::outgrows($latest, $graph);
        $running = $new_version ? $formula->contracts()->notEnded()->get() : collect();

        return [
            'new_version' => $new_version,
            'number' => $new_version ? $latest->number + 1 : null,
            'running' => $running->count(),
            'now' => $with_prices ? UpgradeContractsToLatestVersionAction::periodicTotal($running) : null,
            'then' => $with_prices ? $this->periodicTotalOn($running, $graph) : null,
        ];
    }

    /**
     * What these contracts would cost together per period on a graph not saved
     * yet. A contract the graph cannot price keeps its price, as it would when
     * moved onto it.
     *
     * @param  Collection<int, MaintenanceContract>  $contracts
     */
    private function periodicTotalOn(Collection $contracts, array $graph): float
    {
        $draft = new PriceFormulaVersion(['graph' => $graph]);

        PricedMachines::preload($contracts);

        return round($contracts->sum(function (MaintenanceContract $contract) use ($draft) {
            $result = $this->pricing->evaluate($contract, $draft);

            return ($result['readable'] ?? false)
                ? (float) ($result['periodic'] ?? 0)
                : (float) $contract->getRawOriginal('price');
        }), 2);
    }

    /**
     * What saving told about the running contracts left on an older version:
     * they keep their price until somebody moves them.
     */
    public static function behindNotice(int $behind): string
    {
        return $behind === 1
            ? 'Eén contract staat nog op een oudere versie en houdt zijn prijs tot je het bijwerkt.'
            : $behind . ' contracten staan nog op een oudere versie en houden hun prijs tot je ze bijwerkt.';
    }

    /** A graph that prices differently than a version contracts are on needs a version of its own. */
    private static function outgrows(PriceFormulaVersion $latest, array $graph): bool
    {
        return self::pricing($latest->graph) !== self::pricing($graph) && $latest->contracts()->exists();
    }

    /**
     * What of a graph decides a price: the blocks with their settings and the
     * lines between them, in a fixed order, without where they stand or what
     * they are called. Keys too go in a fixed order: the database hands a saved
     * graph back with its keys reordered, and a line read back must still be
     * the same line.
     */
    public static function pricing(array $graph): string
    {
        $nodes = collect($graph['nodes'] ?? [])
            ->map(fn (array $node) => [
                'id' => $node['id'] ?? null,
                'type' => $node['type'] ?? null,
                'data' => self::normalized(Arr::except(
                    (array) ($node['data'] ?? []),
                    ($node['type'] ?? null) === 'input' ? ['title', 'name'] : ['title']
                )),
            ])
            ->sortBy('id')
            ->values();
        $edges = collect($graph['edges'] ?? [])
            ->map(fn (array $edge) => [
                'source' => $edge['source'] ?? null,
                'sourceHandle' => $edge['sourceHandle'] ?? null,
                'target' => $edge['target'] ?? null,
                'targetHandle' => $edge['targetHandle'] ?? null,
            ])
            ->sortBy(fn (array $edge) => implode('|', $edge))
            ->values();

        return json_encode(['nodes' => $nodes, 'edges' => $edges]);
    }

    private static function normalized(mixed $value): mixed
    {
        return match (true) {
            is_array($value) => array_map(self::normalized(...), Arr::isAssoc($value) ? Arr::sortRecursive($value) : $value),
            is_numeric($value) => (float) $value,
            default => $value,
        };
    }
}

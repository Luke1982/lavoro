<?php

namespace App\Actions\Pricing;

use App\Domain\Pricing\CompactFormula;
use App\Domain\Signals\Pricing\PriceFormulaSaved;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
use App\Models\PriceFormula;
use App\Models\PriceFormulaVersion;
use App\Models\User;
use Illuminate\Support\Arr;
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
 */
class SavePriceFormulaGraphAction
{
    public function __construct(private readonly CompactFormula $compact) {}

    /**
     * @param  int|null  $based_on  the version number the graph was edited from; a newer one saved in the meantime refuses
     * @return array{version: PriceFormulaVersion, is_new_version: bool, behind: int}
     */
    public function execute(PriceFormula $formula, array $graph, ?User $user, ?int $based_on = null): array
    {
        return DB::transaction(function () use ($formula, $graph, $user, $based_on) {
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
            } else {
                $version = $formula->versions()->create([
                    'number' => ($latest?->number ?? 0) + 1,
                    'graph' => $graph,
                    'created_by' => $user?->id,
                ]);
                $is_new = $latest !== null;
            }

            if ($previous !== $summary || $prices_changed) {
                Signals::dispatch(new PriceFormulaSaved($formula, $version->number, $is_new, $previous, $summary));
            }

            return [
                'version' => $version,
                'is_new_version' => $is_new,
                'behind' => $formula->contractsBehind()->notEnded()->count(),
            ];
        });
    }

    /** Whether saving this graph now would make a new version rather than change the newest one. */
    public static function makesNewVersion(PriceFormula $formula, array $graph): bool
    {
        $latest = $formula->versions()->first();

        return $latest !== null && self::outgrows($latest, $graph);
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

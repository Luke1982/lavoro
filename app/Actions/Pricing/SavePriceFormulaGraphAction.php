<?php

namespace App\Actions\Pricing;

use App\Domain\Pricing\CompactFormula;
use App\Domain\Signals\Pricing\PriceFormulaSaved;
use App\Domain\Signals\Signals;
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
 */
class SavePriceFormulaGraphAction
{
    public function __construct(private readonly CompactFormula $compact) {}

    /** @return array{version: PriceFormulaVersion, is_new_version: bool} */
    public function execute(PriceFormula $formula, array $graph, ?User $user): array
    {
        return DB::transaction(function () use ($formula, $graph, $user) {
            PriceFormula::whereKey($formula->id)->lockForUpdate()->first();
            $latest = $formula->versions()->first();
            $previous = $latest ? $this->compact->summary($latest->graph) : null;
            $summary = $this->compact->summary($graph);
            $same_prices = $latest && self::pricing($latest->graph) === self::pricing($graph);

            if ($latest && ($same_prices || !$latest->contracts()->exists())) {
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

            if ($previous !== $summary || !$same_prices) {
                Signals::dispatch(new PriceFormulaSaved($formula, $version->number, $is_new, $previous, $summary));
            }

            return ['version' => $version, 'is_new_version' => $is_new];
        });
    }

    /**
     * What of a graph decides a price: the blocks with their settings and the
     * lines between them, in a fixed order, without where they stand or what
     * they are called.
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
            ->map(fn (array $edge) => Arr::only($edge, ['source', 'sourceHandle', 'target', 'targetHandle']))
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

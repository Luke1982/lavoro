<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The Aantal block is gone: a machines line into a number input counts the
 * machines itself. Every Aantal block in a stored formula goes, and every price
 * stays exactly what it was.
 *
 * Where it is safe, the lines through an Aantal block are joined up: machines
 * straight into the input it fed. That is not safe when two Aantal blocks fed
 * the same input (a machine on both lines was counted twice, a joined input
 * counts it once), when nothing came into the Aantal block (its 0 would give way
 * to the input's own setting), or when it fed a comparison (which counts without
 * pricing). There the block becomes an Optellen block in the same place, which
 * counts the machines wired into it exactly as Aantal did.
 */
return new class extends Migration
{
    private const COMPARING_INPUTS = ['condition' => ['value', 'compare']];

    public function up(): void
    {
        DB::table('price_formula_versions')->orderBy('id')->each(function (object $version) {
            $graph = json_decode($version->graph, true);

            if (!is_array($graph) || !collect($graph['nodes'] ?? [])->contains('type', 'count')) {
                return;
            }

            DB::table('price_formula_versions')
                ->where('id', $version->id)
                ->update(['graph' => json_encode($this->withoutCounts($graph))]);
        });
    }

    public function down(): void {}

    private function withoutCounts(array $graph): array
    {
        $nodes = collect($graph['nodes']);
        $edges = collect($graph['edges'] ?? []);
        $counts = $nodes->where('type', 'count')->pluck('id')->all();
        $joined = array_values(array_filter($counts, fn (string $count) => $this->joinable($count, $counts, $nodes, $edges)));
        $through_joined = fn (array $edge) => in_array($edge['source'], $joined, true) || in_array($edge['target'], $joined, true);

        $new_edges = [];
        foreach ($joined as $count) {
            foreach ($edges->where('target', $count) as $in) {
                foreach ($edges->where('source', $count) as $out) {
                    $edge = [
                        'source' => $in['source'],
                        'sourceHandle' => $in['sourceHandle'],
                        'target' => $out['target'],
                        'targetHandle' => $out['targetHandle'],
                    ];
                    $new_edges[] = ['id' => 'e-' . substr(md5(implode('|', $edge)), 0, 12), ...$edge];
                }
            }
        }

        return [
            ...$graph,
            'nodes' => $nodes
                ->reject(fn (array $node) => in_array($node['id'], $joined, true))
                ->map(fn (array $node) => $node['type'] === 'count' ? [...$node, 'type' => 'sum'] : $node)
                ->values()
                ->all(),
            'edges' => $edges
                ->reject($through_joined)
                ->map(fn (array $edge) => in_array($edge['target'], $counts, true)
                    ? [...$edge, 'targetHandle' => 'values']
                    : $edge)
                ->concat($new_edges)
                ->values()
                ->all(),
        ];
    }

    /** Whether joining the lines through this Aantal block leaves every count, and so every price, as it was. */
    private function joinable(string $count, array $counts, Collection $nodes, Collection $edges): bool
    {
        if ($edges->where('target', $count)->isEmpty()) {
            return false;
        }

        foreach ($edges->where('source', $count) as $out) {
            $target_type = $nodes->firstWhere('id', $out['target'])['type'] ?? null;
            $other_counts_into_it = $edges->contains(fn (array $edge) => $edge['target'] === $out['target']
                && $edge['targetHandle'] === $out['targetHandle']
                && $edge['source'] !== $count
                && in_array($edge['source'], $counts, true));

            if ($other_counts_into_it || in_array($out['targetHandle'], self::COMPARING_INPUTS[$target_type] ?? [], true)) {
                return false;
            }
        }

        return true;
    }
};

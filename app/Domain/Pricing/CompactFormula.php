<?php

namespace App\Domain\Pricing;

use App\Models\Brand;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductType;
use App\Rules\PriceFormulaGraph;
use Illuminate\Database\Eloquent\Builder;

/**
 * A price formula in the shape the assistant writes and reads: blocks with a
 * short id, settings by name ("product_type": "Compressoren") and wires as
 * "blok.uitgang -> blok.ingang". The editor's shape has database ids and canvas
 * positions; this turns one into the other, looks the names up, and lays the
 * blocks out from left to right in the order they are wired.
 *
 * Every problem names the block it is about by its id, so the assistant can fix
 * its own draft before anybody is asked to confirm it.
 */
final class CompactFormula
{
    private const COLUMN = 340;

    private const ROW = 190;

    private const SHOWN_CANDIDATES = 8;

    /** @var array<int, string> */
    private array $problems = [];

    /** @var array<string, array<int, string>> id → name per kind of record, read once */
    private array $names = [];

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<int, mixed>  $wires
     * @param  array<string, mixed>|null  $previous  the graph it replaces; its blocks keep their place
     * @return array{graph: array{nodes: array, edges: array}, problems: array<int, string>}
     */
    public function toGraph(array $blocks, array $wires, ?array $previous = null): array
    {
        $this->problems = [];
        $nodes = [];

        foreach ($blocks as $block) {
            $node = is_array($block) ? $this->node($block) : null;

            if ($node === null) {
                continue;
            }

            if (isset($nodes[$node['id']])) {
                $this->problems[] = 'Twee blokken heten "' . $node['id'] . '".';

                continue;
            }

            $nodes[$node['id']] = $node;
        }

        $edges = [];
        foreach ($wires as $wire) {
            $edge = $this->edge($wire, $nodes);

            if ($edge !== null) {
                $edges[$edge['id']] = $edge;
            }
        }

        $graph = ['nodes' => $this->laidOut($nodes, $edges, $previous), 'edges' => array_values($edges)];

        if ($this->problems === []) {
            $this->problems = (new PriceFormulaGraph)->problems($graph);
        }

        return ['graph' => $graph, 'problems' => $this->problems];
    }

    /**
     * A saved graph as the assistant reads it: settings by name, wires as text.
     *
     * @return array{blocks: array<int, array<string, mixed>>, wires: array<int, string>}
     */
    public function fromGraph(array $graph): array
    {
        $this->names['product'] = $this->productNames(NodeCatalog::productIdsIn($graph));

        $blocks = array_map(fn (array $node) => array_filter([
            'id' => $node['id'],
            'type' => $node['type'],
            'title' => $node['data']['title'] ?? null,
            'settings' => $this->namedSettings($node) ?: null,
        ], fn ($value) => $value !== null), $graph['nodes'] ?? []);

        $wires = array_map(
            fn (array $edge) => $edge['source'] . '.' . $edge['sourceHandle'] . ' -> '
                . $edge['target'] . '.' . $edge['targetHandle'],
            $graph['edges'] ?? []
        );

        return ['blocks' => array_values($blocks), 'wires' => array_values($wires)];
    }

    /**
     * The blocks in one line each, as the confirmation the person agrees to
     * reads them: "Prijs per stuk “Airco per stuk”: amount 50, steps …".
     *
     * @return array<int, string>
     */
    public function describe(array $graph): array
    {
        return $this->lines($this->fromGraph($graph)['blocks']);
    }

    /** The blocks and wires in one line: what the history trail compares and shows, positions left out. */
    public function summary(array $graph): string
    {
        $compact = $this->fromGraph($graph);

        return implode('; ', [...$this->lines($compact['blocks']), ...$compact['wires']]);
    }

    /**
     * Made-up machines to try a formula on, written by name, as the trial reads
     * them: {"quantity": 3, "product_type": "Compressoren", "address": 1}.
     *
     * @param  array<int, mixed>  $machines
     * @return array{sample: array<int, array<string, mixed>>, problems: array<int, string>}
     */
    public function sample(array $machines): array
    {
        $this->problems = [];
        $sample = [];

        foreach (array_values($machines) as $index => $machine) {
            $label = 'Machine ' . ($index + 1);
            $sample[] = [
                'quantity' => (int) ($machine['quantity'] ?? 1),
                'product_type_id' => $this->lookup($label, 'product_type', $machine['product_type'] ?? null),
                'product_id' => $this->lookup($label, 'product', $machine['product'] ?? null),
                'address' => (int) ($machine['address'] ?? 1),
            ];
        }

        return ['sample' => $sample, 'problems' => $this->problems];
    }

    /**
     * Every block type as the assistant needs to know it, with its inputs, its
     * outputs and its settings.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function catalogue(): array
    {
        $handles = fn (array $list) => array_map(
            fn (array $handle) => $handle['id'] . ' (' . $handle['kind'] . ')',
            $list
        );

        return array_map(function (string $type, array $definition) use ($handles) {
            $settings = [];
            foreach ($definition['fields'] as $field) {
                $settings[self::settingName($field)] = trim($field['label'] . ': ' . self::settingHint($field));
            }

            return array_filter([
                'type' => $type,
                'naam' => $definition['label'],
                'doet' => $definition['description'],
                'in' => $handles($definition['inputs']) ?: null,
                'uit' => $handles($definition['outputs']) ?: null,
                'instellingen' => $settings ?: null,
            ], fn ($value) => $value !== null);
        }, array_keys(NodeCatalog::definitions()), NodeCatalog::definitions());
    }

    private static function settingName(array $field): string
    {
        return NodeCatalog::REFERENCES[$field['type']]['setting'] ?? $field['key'];
    }

    private static function settingHint(array $field): string
    {
        return match ($field['type']) {
            'select' => 'een van ' . implode(', ', array_map(
                fn ($key, $label) => $key . ' (' . $label . ')',
                array_keys($field['options']),
                $field['options']
            )),
            'product_type' => 'naam of pad van een producttype; subtypes tellen mee',
            'product' => 'merk en model uit de catalogus',
            'brand' => 'naam van een merk',
            'attribute' => 'naam van een kenmerk; is het kenmerk een getal met een eenheid, dan vergelijk je '
                . 'op het getal in die eenheid ("10" voor 10 kW)',
            'tiers' => 'lijst van {"from": stuk vanaf 2, "amount": bedrag per stuk}',
            'components' => 'lijst van {"quantity": aantal, "product_type" of "product": naam}',
            'number' => 'getal' . (isset($field['default']) ? ', standaard ' . json_encode($field['default']) : ''),
            default => 'tekst',
        };
    }

    /** @param array<int, array<string, mixed>> $blocks */
    private function lines(array $blocks): array
    {
        return array_map(function (array $block) {
            $settings = array_map(
                fn ($key, $value) => $key . ' '
                    . (is_array($value) ? json_encode($value, JSON_UNESCAPED_UNICODE) : $value),
                array_keys($block['settings'] ?? []),
                $block['settings'] ?? []
            );

            return NodeCatalog::definition($block['type'])['label']
                . (isset($block['title']) ? ' “' . $block['title'] . '”' : '')
                . ($settings === [] ? '' : ': ' . implode(', ', $settings));
        }, $blocks);
    }

    /** @return array<string, mixed>|null */
    private function node(array $block): ?array
    {
        $id = preg_replace('/[^A-Za-z0-9_-]/', '-', trim((string) ($block['id'] ?? '')));
        $type = (string) ($block['type'] ?? '');
        $definition = NodeCatalog::definition($type);

        if ($id === '' || strlen($id) > 40) {
            $this->problems[] = 'Een blok-id mag hoogstens 40 tekens hebben.';

            return null;
        }

        if ($definition === null) {
            $this->problems[] = 'Blok "' . $id . '" heeft een onbekend type "' . $type . '". Kies uit: '
                . implode(', ', array_keys(NodeCatalog::definitions())) . '.';

            return null;
        }

        $settings = is_array($block['settings'] ?? null) ? $block['settings'] : [];
        $data = array_filter(['title' => trim((string) ($block['title'] ?? '')) ?: null]);
        $known = [];

        foreach ($definition['fields'] as $field) {
            $name = self::settingName($field);
            $known[] = $name;
            $value = $settings[$name] ?? $settings[$field['key']] ?? null;

            if ($value !== null) {
                $data[$field['key']] = $this->resolvedSetting($id, $field, $value);
            }
        }

        $unknown = array_diff(array_keys($settings), $known, array_column($definition['fields'], 'key'));

        if ($unknown !== []) {
            $this->problems[] = 'Blok "' . $id . '" (' . $definition['label'] . ') kent ' . implode(', ', $unknown)
                . ' niet. ' . ($known === [] ? 'Het heeft geen instellingen.' : 'Wel: ' . implode(', ', $known) . '.');
        }

        return ['id' => $id, 'type' => $type, 'position' => ['x' => 0, 'y' => 0], 'data' => $data];
    }

    private function resolvedSetting(string $id, array $field, mixed $value): mixed
    {
        return match ($field['type']) {
            'product_type', 'product', 'brand', 'attribute' => $this->lookup($id, $field['type'], $value),
            'components' => array_map(
                fn ($component) => $this->component($id, (array) $component),
                array_values((array) $value)
            ),
            'number' => is_numeric($value) ? $value + 0 : $value,
            'select' => (string) $value,
            default => $value,
        };
    }

    /** One part of a combination: a model when one is named, otherwise a type. */
    private function component(string $id, array $component): array
    {
        $model = $component['product'] ?? $component['product_id'] ?? null;
        $type = $component['product_type'] ?? $component['product_type_id'] ?? null;

        return [
            'quantity' => (int) ($component['quantity'] ?? 1),
            'product_type_id' => $model === null ? $this->lookup($id, 'product_type', $type) : null,
            'product_id' => $model === null ? null : $this->lookup($id, 'product', $model),
        ];
    }

    /**
     * A record by id or by name. A name that fits exactly one, or contains the
     * words of exactly one, is that one; otherwise the problem lists candidates.
     */
    private function lookup(string $id, string $kind, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        $name = mb_strtolower(trim((string) $value));
        $candidates = $kind === 'product' ? $this->productsNamed($name) : $this->namesOf($kind);
        $own_name = fn (string $label) => mb_strtolower(trim((string) last(explode('→', $label))));
        $matching = fn (callable $test) => array_keys(array_filter($candidates, $test));
        $exact = $matching(fn (string $label) => mb_strtolower($label) === $name || $own_name($label) === $name);
        $close = $exact ?: $matching(fn (string $label) => str_contains(mb_strtolower($label), $name));

        if (count($close) === 1) {
            return (int) $close[0];
        }

        $noun = NodeCatalog::REFERENCES[$kind]['noun'];
        $shown = array_slice(array_map(fn ($key) => $candidates[$key], $close), 0, self::SHOWN_CANDIDATES);

        $this->problems[] = $id . ': ' . ($close === []
            ? 'er is geen ' . $noun . ' "' . $value . '".'
            : $noun . ' "' . $value . '" past bij meer dan één: ' . implode('; ', $shown) . '. Kies er één.');

        return null;
    }

    /** @return array<int, string> id → name as a person reads it */
    private function namesOf(string $kind): array
    {
        return $this->names[$kind] ??= match ($kind) {
            'product_type' => ProductType::pathMap(),
            'brand' => Brand::pluck('name', 'id')->all(),
            'attribute' => ProductAttribute::pluck('name', 'id')->all(),
            'product' => [],
        };
    }

    /**
     * The catalogue can be large, so a model is searched rather than read whole:
     * every word of the name in its brand or its model.
     *
     * @return array<int, string>
     */
    private function productsNamed(string $name): array
    {
        $query = Product::with('brand')->limit(50);

        foreach (preg_split('/\s+/', $name) ?: [] as $word) {
            $query->where(fn (Builder $product) => $product->where('model', 'like', '%' . $word . '%')
                ->orWhereHas('brand', fn (Builder $brand) => $brand->where('name', 'like', '%' . $word . '%')));
        }

        return $this->displayNames($query);
    }

    /** @return array<int, string> */
    private function productNames(array $ids): array
    {
        return $ids === [] ? [] : $this->displayNames(Product::with('brand')->whereKey($ids));
    }

    /** @return array<int, string> */
    private function displayNames(Builder $products): array
    {
        return $products->get()
            ->mapWithKeys(fn (Product $product) => [$product->id => (string) $product->display_name])
            ->all();
    }

    /** @param array<string, array<string, mixed>> $nodes */
    private function edge(mixed $wire, array $nodes): ?array
    {
        [$from, $to] = is_array($wire)
            ? [(string) ($wire['from'] ?? ''), (string) ($wire['to'] ?? '')]
            : array_pad(array_map('trim', explode('->', (string) $wire)), 2, '');

        [$source, $source_handle] = array_pad(explode('.', $from, 2), 2, '');
        [$target, $target_handle] = array_pad(explode('.', $to, 2), 2, '');

        foreach ([$source, $target] as $end) {
            if (!isset($nodes[$end])) {
                $this->problems[] = 'De lijn "' . $from . ' -> ' . $to . '" loopt naar een blok "' . $end
                    . '" dat niet bestaat.';

                return null;
            }
        }

        return [
            'id' => 'e-' . $source . '-' . $source_handle . '-' . $target . '-' . $target_handle,
            'source' => $source,
            'sourceHandle' => $source_handle,
            'target' => $target,
            'targetHandle' => $target_handle,
        ];
    }

    /**
     * Blocks in columns by how many wires lie before them, so a formula reads
     * from left to right like the ones people draw. A block that was already on
     * the canvas stays where somebody put it; new ones go below.
     *
     * @param  array<string, array<string, mixed>>  $nodes
     * @param  array<string, array<string, mixed>>  $edges
     * @return array<int, array<string, mixed>>
     */
    private function laidOut(array $nodes, array $edges, ?array $previous): array
    {
        $kept = collect($previous['nodes'] ?? [])->keyBy('id')->map(fn ($node) => $node['position'] ?? null)->filter();
        $top = $kept->isEmpty() ? 0 : $kept->max('y') + self::ROW * 1.5;
        $depth = $this->depths($nodes, $edges);
        $rows = [];

        foreach ($nodes as $id => $node) {
            if ($kept->has($id)) {
                $nodes[$id]['position'] = $kept->get($id);

                continue;
            }

            $column = $depth[$id];
            $rows[$column] = ($rows[$column] ?? -1) + 1;
            $nodes[$id]['position'] = ['x' => $column * self::COLUMN, 'y' => $top + $rows[$column] * self::ROW];
        }

        return array_values($nodes);
    }

    /**
     * How many wires lie before each block on the longest way in. A loop, which
     * the validation refuses anyway, stops the counting instead of running on.
     *
     * @return array<string, int>
     */
    private function depths(array $nodes, array $edges): array
    {
        $depth = array_fill_keys(array_keys($nodes), 0);

        for ($round = 0; $round < count($nodes); $round++) {
            $changed = false;

            foreach ($edges as $edge) {
                $further = ($depth[$edge['source']] ?? 0) + 1;

                if (isset($depth[$edge['target']]) && $depth[$edge['target']] < $further) {
                    $depth[$edge['target']] = $further;
                    $changed = true;
                }
            }

            if (!$changed) {
                break;
            }
        }

        return $depth;
    }

    /** The settings of a block with records by name, under the names the assistant writes them with. */
    private function namedSettings(array $node): array
    {
        $settings = [];

        foreach (NodeCatalog::definition($node['type'])['fields'] ?? [] as $field) {
            $value = $node['data'][$field['key']] ?? null;

            if ($value === null || $value === '' || $value === []) {
                continue;
            }

            $settings[self::settingName($field)] = match (true) {
                isset(NodeCatalog::REFERENCES[$field['type']]) => $this->nameOf($field['type'], $value),
                $field['type'] === 'components' => array_map(fn ($component) => array_filter([
                    'quantity' => $component['quantity'] ?? 1,
                    'product_type' => $this->nameOf('product_type', $component['product_type_id'] ?? null),
                    'product' => $this->nameOf('product', $component['product_id'] ?? null),
                ], fn ($item) => $item !== null), $value),
                default => $value,
            };
        }

        return $settings;
    }

    private function nameOf(string $kind, mixed $id): mixed
    {
        if (empty($id)) {
            return null;
        }

        return ($kind === 'product' ? $this->names['product'] ?? [] : $this->namesOf($kind))[(int) $id] ?? $id;
    }
}

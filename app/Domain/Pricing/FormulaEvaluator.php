<?php

namespace App\Domain\Pricing;

use App\Models\ProductType;

/**
 * Works a price formula's graph out over a set of machines: what every block
 * gives, and what lands in "Prijs per periode" and "Eenmalige kosten". The
 * contract's stored price, the editor's live values and the "what would the
 * new version cost" on a contract all come from here.
 *
 * Blocks are worked out from the results backwards, each once. The machines
 * priced along the way are noted: those wired into a number, which counts them,
 * those reaching Catalogusprijs or Onderhoudsbeurten, and those in a Combinatie
 * whose count is used. Once a formula prices per machine at all, or leaves the
 * Machines block unwired, every machine priced nowhere is reported, because it
 * costs nothing and that is rarely what was meant; a formula charging only per
 * address or a fixed amount reports none. Blocks not wired to a result are
 * still worked out for the editor, but price nothing.
 *
 * A machine is the array PricedMachines makes. The context carries the inputs
 * filled in on the contract, by block id, and the contract's own figures.
 */
final class FormulaEvaluator
{
    private const SHOWN_LABELS = 60;

    /** @var array<string, array<string, mixed>> */
    private array $nodes = [];

    /** @var array<string, array<string, array<int, array<string, mixed>>>> target id → handle → edges */
    private array $incoming = [];

    /** @var array<string, array<string, mixed>> */
    private array $memo = [];

    /** @var array<string, true> */
    private array $visiting = [];

    /** @var array<string, string> */
    private array $errors = [];

    /** @var array<string, true> */
    private array $priced = [];

    private bool $tracking = true;

    private bool $prices_per_machine = false;

    /** @var array<string, true> ids of the blocks with a line going out */
    private array $wired_from = [];

    /** A block of a type this code does not know: the graph can be shown, but not priced. */
    private bool $unreadable = false;

    /** @var array<int, array<string, mixed>> */
    private array $machines = [];

    /** @var array<string, mixed> */
    private array $context = [];

    /** @var array<int, ?int>|null */
    private ?array $type_parents = null;

    /**
     * @param  array{nodes?: array, edges?: array}  $graph
     * @param  array<int, array<string, mixed>>  $machines
     * @param  array{inputs?: array<string, mixed>, periods_per_year?: float, months?: int}  $context
     * @return array{nodes: array<string, array<string, mixed>>, periodic: ?float, one_time: ?float,
     *     unpriced: array<int, string>, problems: array<int, string>, readable: bool, machine_count: int}
     */
    public function evaluate(array $graph, array $machines, array $context = []): array
    {
        $this->reset($graph, $machines, $context);

        $results = array_fill_keys(NodeCatalog::resultTypes(), null);

        foreach ($this->nodes as $id => $node) {
            if (array_key_exists($node['type'], $results)) {
                $results[$node['type']] = ($results[$node['type']] ?? 0) + ($this->outputsOf($id)['value'] ?? 0);
            }
        }

        $problems = array_values(array_map(
            fn (string $id) => "'" . $this->labelOf($id) . "': " . $this->errors[$id],
            array_keys($this->errors)
        ));

        if ($this->unreadable) {
            $problems[] = 'Deze formule bevat een blok dat niet meer bestaat en kan daardoor nu geen prijs uitrekenen.';
        }
        $this->tracking = false;
        $shown = [];

        foreach ($this->nodes as $id => $node) {
            $shown[$id] = [
                'outputs' => array_map(fn ($value) => $this->display($value), $this->outputsOf($id)),
                'inputs' => $this->numberInputsOf($id),
                'error' => $this->errors[$id] ?? null,
            ];
        }

        $unpriced = $this->prices_per_machine || $this->machinesLeftLoose()
            ? array_values(array_map(
                fn (array $machine) => $machine['label'],
                array_filter($this->machines, fn (array $machine) => !isset($this->priced[$machine['key']]))
            ))
            : [];

        return [
            'nodes' => $shown,
            'periodic' => $results['output_periodic'] === null ? null : round($results['output_periodic'], 2),
            'one_time' => $results['output_one_time'] === null ? null : round($results['output_one_time'], 2),
            'unpriced' => $unpriced,
            'problems' => $problems,
            'readable' => !$this->unreadable,
            'machine_count' => count($this->machines),
        ];
    }

    private function machinesLeftLoose(): bool
    {
        return collect($this->nodes)->contains(
            fn (array $node, string $id) => $node['type'] === 'machines' && !isset($this->wired_from[$id])
        );
    }

    private function labelOf(string $id): string
    {
        $node = $this->nodes[$id];

        return trim((string) ($node['data']['title'] ?? '')) ?: NodeCatalog::definition($node['type'])['label'];
    }

    private function reset(array $graph, array $machines, array $context): void
    {
        $this->nodes = [];
        $this->incoming = [];
        $this->memo = [];
        $this->visiting = [];
        $this->errors = [];
        $this->priced = [];
        $this->tracking = true;
        $this->prices_per_machine = false;
        $this->wired_from = [];
        $this->unreadable = false;
        $this->context = $context;

        foreach ($graph['nodes'] ?? [] as $node) {
            if (NodeCatalog::definition((string) ($node['type'] ?? '')) !== null) {
                $this->nodes[(string) $node['id']] = $node;
            } else {
                $this->unreadable = true;
            }
        }

        foreach ($graph['edges'] ?? [] as $edge) {
            if (isset($this->nodes[$edge['source']], $this->nodes[$edge['target']])) {
                $this->incoming[$edge['target']][$edge['targetHandle']][] = $edge;
                $this->wired_from[$edge['source']] = true;
            }
        }

        $this->machines = array_map(fn (array $machine) => $machine + [
            'type_chain' => $this->typeChain($machine['product_type_id'] ?? null),
        ], array_values($machines));
    }

    /** @return array<string, mixed> handle → number or list of machines */
    private function outputsOf(string $id): array
    {
        if (isset($this->memo[$id])) {
            return $this->memo[$id];
        }

        if (isset($this->visiting[$id])) {
            return $this->refuse($id, 'Deze blokken vormen samen een kring. Haal een van de lijnen weg.', []);
        }

        $this->visiting[$id] = true;
        $node = $this->nodes[$id];
        $outputs = $this->finite($id, $this->work($id, $node));
        unset($this->visiting[$id]);

        return $this->memo[$id] = $outputs;
    }

    /** A number too large to be a price stops where it arose, instead of spreading as infinity or NaN. */
    private function finite(string $id, array $outputs): array
    {
        foreach ($outputs as $handle => $value) {
            if (is_float($value) && !is_finite($value)) {
                $outputs[$handle] = $this->refuse($id, 'De uitkomst is te groot om mee te rekenen.');
            }
        }

        return $outputs;
    }

    /** @return array<string, mixed> */
    private function work(string $id, array $node): array
    {
        return match ($node['type']) {
            'machines' => ['machines' => $this->machines],
            'number' => ['value' => (float) $this->setting($node, 'value')],
            'input' => ['value' => (float) ($this->context['inputs'][$id] ?? $this->setting($node, 'default'))],
            'contract' => [
                'periods' => (float) ($this->context['periods_per_year'] ?? 1),
                'months' => (float) ($this->context['months'] ?? 0),
            ],
            'filter_type' => $this->split($id, $node, 'product_type_ids', 'Kies een producttype.',
                fn (array $machine, $type_ids) => array_intersect(array_map('intval', (array) $type_ids), $machine['type_chain']) !== []),
            'filter_model' => $this->split($id, $node, 'product_id', 'Kies een model.',
                fn (array $machine, $product_id) => (int) $product_id === (int) ($machine['product_id'] ?? 0)),
            'filter_brand' => $this->split($id, $node, 'brand_id', 'Kies een merk.',
                fn (array $machine, $brand_id) => (int) $brand_id === (int) ($machine['brand_id'] ?? 0)),
            'filter_attribute' => $this->split($id, $node, 'product_attribute_id', 'Kies een kenmerk.',
                fn (array $machine, $attribute_id) => $this->attributeMatches($node, $machine, (int) $attribute_id)),
            'combination' => $this->combination($id, $node),
            'addresses' => ['value' => $this->addressCount($id)],
            'catalog_value' => ['value' => (float) array_sum(array_map(
                fn (array $machine) => (float) ($machine[$this->setting($node, 'field')] ?? 0),
                $this->pricedFrom($id)
            ))],
            'visits' => ['value' => (float) array_sum(array_column($this->pricedFrom($id), 'visits_per_year'))],
            'tiers' => ['value' => $this->tiers($node, $this->numberIn($id, 'quantity') ?? 0)],
            'sum' => ['value' => $this->numberIn($id, 'values') ?? 0.0],
            'multiply' => ['value' => ($this->numberIn($id, 'a') ?? 0.0) * ($this->numberIn($id, 'b') ?? 0.0)],
            'divide' => ['value' => $this->divided($id)],
            'subtract' => ['value' => ($this->numberIn($id, 'a') ?? 0.0) - ($this->numberIn($id, 'b') ?? 0.0)],
            'minimum' => ['value' => max($this->numberIn($id, 'a') ?? 0.0, $this->numberIn($id, 'b') ?? 0.0)],
            'maximum' => ['value' => min($this->numberIn($id, 'a') ?? 0.0, $this->numberIn($id, 'b') ?? 0.0)],
            'percentage' => $this->percentage($id, $node),
            'round' => ['value' => $this->rounded($node, $this->numberIn($id, 'value') ?? 0)],
            'condition' => ['value' => $this->condition($id)],
            'output_periodic', 'output_one_time' => ['value' => $this->numberIn($id, 'value') ?? 0.0],
            default => [],
        };
    }

    /** @return array{match: array, rest: array} */
    private function split(string $id, array $node, string $key, string $missing, callable $test): array
    {
        $machines = $this->machinesIn($id, 'machines');
        $setting = $this->setting($node, $key);

        if (in_array($setting, [null, '', []], true)) {
            return $this->refuse($id, $missing, ['match' => [], 'rest' => $machines]);
        }

        $match = [];
        $rest = [];

        foreach ($machines as $machine) {
            if ($test($machine, $setting)) {
                $match[] = $machine;
            } else {
                $rest[] = $machine;
            }
        }

        return ['match' => $match, 'rest' => $rest];
    }

    private function attributeMatches(array $node, array $machine, int $attribute_id): bool
    {
        $actual = $machine['attributes'][$attribute_id] ?? null;

        if ($actual === null) {
            return false;
        }

        $expected = (string) $this->setting($node, 'value');
        $operator = (string) $this->setting($node, 'operator');

        if ($operator === 'contains') {
            return $expected !== '' && mb_stripos((string) $actual, $expected) !== false;
        }

        $actual_number = $this->leadingNumber((string) $actual);
        $expected_number = $this->leadingNumber($expected);

        if ($operator === 'between') {
            $until = $this->leadingNumber((string) $this->setting($node, 'value_to'));

            return $actual_number !== null && $expected_number !== null && $until !== null
                && $actual_number >= min($expected_number, $until) && $actual_number <= max($expected_number, $until);
        }

        if ($actual_number !== null && $expected_number !== null) {
            return $this->compare($actual_number, $operator, $expected_number);
        }

        $same = mb_strtolower(trim((string) $actual)) === mb_strtolower(trim($expected));

        return match ($operator) {
            '=' => $same,
            '!=' => !$same,
            default => false,
        };
    }

    /**
     * Forms as many complete combinations as fit at each address. A machine
     * fitting two parts of the same combination is used for the first part.
     * The machines in one count as priced only once their number is used, in
     * numberIn: a combination whose count reaches nothing prices nothing.
     *
     * @return array{count: float, used: array, rest: array}
     */
    private function combination(string $id, array $node): array
    {
        $machines = $this->machinesIn($id, 'machines');
        $components = array_values(array_filter(
            (array) $this->setting($node, 'components'),
            fn ($component) => (int) ($component['quantity'] ?? 0) >= 1
                && (!empty($component['product_type_id']) || !empty($component['product_id']))
        ));

        if ($components === []) {
            return $this->refuse($id, 'Geef aan waaruit de combinatie bestaat.', ['count' => 0.0, 'used' => [], 'rest' => $machines]);
        }

        $by_address = [];
        foreach ($machines as $machine) {
            $by_address[$machine['address_key']][] = $machine;
        }

        $sets = 0;
        $used = [];

        foreach ($by_address as $address_machines) {
            $queues = array_map(
                fn (array $component) => array_values(array_filter(
                    $address_machines,
                    fn (array $machine) => $this->fitsComponent($component, $machine)
                )),
                $components
            );
            $cursors = array_fill(0, count($components), 0);

            while (true) {
                $picked = [];

                foreach ($components as $index => $component) {
                    $needed = (int) $component['quantity'];

                    while ($needed > 0 && $cursors[$index] < count($queues[$index])) {
                        $machine = $queues[$index][$cursors[$index]++];
                        $key = $machine['key'];

                        if (!isset($picked[$key]) && !isset($used[$key])) {
                            $picked[$key] = $machine;
                            $needed--;
                        }
                    }

                    if ($needed > 0) {
                        break 2;
                    }
                }

                $sets++;
                $used += $picked;
            }
        }

        return [
            'count' => (float) $sets,
            'used' => array_values($used),
            'rest' => array_values(array_filter(
                $machines,
                fn (array $machine) => !isset($used[$machine['key']])
            )),
        ];
    }

    private function fitsComponent(array $component, array $machine): bool
    {
        if (!empty($component['product_id'])) {
            return (int) $component['product_id'] === (int) ($machine['product_id'] ?? 0);
        }

        return in_array((int) $component['product_type_id'], $machine['type_chain'], true);
    }

    /**
     * A price per piece. Graduated: each step's amount applies from that piece on,
     * so a piece more never makes it cheaper. Volume: once a step is reached, every
     * piece gets its amount. A part of a piece, as from a number of visits per
     * period, pays its share of the next piece's price.
     */
    private function tiers(array $node, float $quantity): float
    {
        $quantity = max(0.0, $quantity);
        $steps = collect((array) $this->setting($node, 'steps'))
            ->filter(fn ($step) => (int) ($step['from'] ?? 0) >= 2)
            ->map(fn ($step) => ['from' => (int) $step['from'], 'amount' => (float) ($step['amount'] ?? 0)])
            ->sortBy('from')
            ->values()
            ->prepend(['from' => 1, 'amount' => (float) $this->setting($node, 'amount')])
            ->all();

        $price_at = fn (float $piece): float => collect($steps)->last(fn ($step) => $step['from'] <= $piece)['amount'];

        if ($this->setting($node, 'mode') === 'volume') {
            return $quantity * $price_at(max(1.0, ceil($quantity)));
        }

        $whole = floor($quantity);
        $total = 0.0;

        foreach ($steps as $index => $step) {
            $last = isset($steps[$index + 1]) ? $steps[$index + 1]['from'] - 1 : $whole;
            $total += max(0.0, min($last, $whole) - $step['from'] + 1) * $step['amount'];
        }

        return $total + ($quantity - $whole) * $price_at($whole + 1);
    }

    private function divided(string $id): float
    {
        $b = $this->numberIn($id, 'b') ?? 0.0;

        return $b == 0.0 ? $this->refuse($id, 'Delen door nul kan niet.') : ($this->numberIn($id, 'a') ?? 0.0) / $b;
    }

    /** @return array{result: float, part: float} */
    private function percentage(string $id, array $node): array
    {
        $value = $this->numberIn($id, 'value') ?? 0.0;
        $part = $value * (float) $this->setting($node, 'percent') / 100;

        return ['result' => $value + $part, 'part' => $part];
    }

    private function rounded(array $node, float $value): float
    {
        $step = (float) $this->setting($node, 'step') ?: 1.0;
        $units = $value / $step;

        $units = match ($this->setting($node, 'direction')) {
            'up' => ceil(round($units, 6)),
            'down' => floor(round($units, 6)),
            default => round($units),
        };

        return $units * $step;
    }

    private function condition(string $id): float
    {
        $node = $this->nodes[$id];
        $holds = $this->compare(
            $this->numberIn($id, 'value') ?? 0.0,
            (string) $this->setting($node, 'operator'),
            $this->numberIn($id, 'compare') ?? 0.0,
        );

        return ($holds ? $this->numberIn($id, 'then') : $this->numberIn($id, 'else')) ?? 0.0;
    }

    private function compare(float $left, string $operator, float $right): bool
    {
        return match ($operator) {
            '>' => $left > $right,
            '=' => abs($left - $right) < 0.000001,
            '!=' => abs($left - $right) >= 0.000001,
            '<' => $left < $right,
            '<=' => $left <= $right,
            default => $left >= $right,
        };
    }

    /**
     * What arrives on each number input, as the block works with it: the wires
     * added up, or the setting standing in for a wire. The editor shows these
     * beside the inputs instead of adding them up a second time.
     *
     * @return array<string, ?float>
     */
    private function numberInputsOf(string $id): array
    {
        $inputs = [];

        foreach (NodeCatalog::definition($this->nodes[$id]['type'])['inputs'] as $input) {
            if ($input['kind'] === NodeCatalog::NUMBER) {
                $value = $this->numberIn($id, $input['id']);
                $inputs[$input['id']] = $value === null ? null : round($value, 4);
            }
        }

        return $inputs;
    }

    /** On how many addresses the machines wired in stand; machines without a location count as one. */
    private function addressCount(string $id): float
    {
        return (float) count(array_unique(array_column($this->machinesIn($id, 'machines'), 'address_key')));
    }

    /** The machines wired into a block that prices them, noted as priced. */
    private function pricedFrom(string $id): array
    {
        $machines = $this->machinesIn($id, 'machines');
        $this->notePriced($machines);

        return $machines;
    }

    private function notePriced(array $machines): void
    {
        if (!$this->tracking) {
            return;
        }

        $this->prices_per_machine = true;

        foreach ($machines as $machine) {
            $this->priced[$machine['key']] = true;
        }
    }

    /** @return array<int, array<string, mixed>> every machine wired into the handle, each once */
    private function machinesIn(string $id, string $handle): array
    {
        $machines = [];

        foreach ($this->incoming[$id][$handle] ?? [] as $edge) {
            $machines += array_column($this->outputsOf($edge['source'])[$edge['sourceHandle']] ?? [], null, 'key');
        }

        return array_values($machines);
    }

    /**
     * The numbers wired into the handle added up, its fallback setting, or null
     * for nothing at all. Machines wired into a number count as how many there
     * are, each once, and count as priced from then on: that is what wiring
     * them to a price means. An input that only compares, as in "Als … dan",
     * counts them without pricing them.
     */
    private function numberIn(string $id, string $handle): ?float
    {
        $node = $this->nodes[$id];
        $input = collect(NodeCatalog::definition($node['type'])['inputs'])->firstWhere('id', $handle);
        $edges = $this->incoming[$id][$handle] ?? [];

        if ($edges === []) {
            return isset($input['fallback']) ? (float) $this->setting($node, $input['fallback']) : null;
        }

        $prices = empty($input['compares']);
        $sum = 0.0;
        $machines = null;

        foreach ($edges as $edge) {
            $outputs = $this->outputsOf($edge['source']);
            $value = $outputs[$edge['sourceHandle']] ?? 0.0;

            if (is_array($value)) {
                $machines = ($machines ?? []) + array_column($value, null, 'key');

                continue;
            }

            $sum += is_numeric($value) ? (float) $value : 0.0;

            if ($prices && $this->nodes[$edge['source']]['type'] === 'combination' && $edge['sourceHandle'] === 'count') {
                $this->notePriced($outputs['used'] ?? []);
            }
        }

        if ($prices && $machines !== null) {
            $this->notePriced($machines);
        }

        return $sum + count($machines ?? []);
    }

    /** A setting left empty takes the catalogue's default, as the editor shows it. */
    private function setting(array $node, string $key): mixed
    {
        $value = $node['data'][$key] ?? null;

        if ($value !== null && $value !== '') {
            return $value;
        }

        $field = collect(NodeCatalog::definition($node['type'])['fields'])->firstWhere('key', $key);

        return $field['default'] ?? null;
    }

    /** Notes what keeps a block from working, and gives what it stands in with. */
    private function refuse(string $id, string $message, mixed $instead = 0.0): mixed
    {
        $this->errors[$id] = $message;

        return $instead;
    }

    private function display(mixed $value): array
    {
        if (is_array($value)) {
            return [
                'kind' => NodeCatalog::MACHINES,
                'count' => count($value),
                'machines' => array_map(
                    fn (array $machine) => ['name' => $machine['name'] ?? $machine['label'], 'detail' => $machine['detail'] ?? ''],
                    array_slice($value, 0, self::SHOWN_LABELS)
                ),
            ];
        }

        return ['kind' => NodeCatalog::NUMBER, 'value' => round((float) $value, 4)];
    }

    private function leadingNumber(string $text): ?float
    {
        return preg_match('/-?\d+(?:[.,]\d+)?/', $text, $match) ? (float) str_replace(',', '.', $match[0]) : null;
    }

    /** @return array<int, int> the type itself first, then each parent up to the root */
    private function typeChain(?int $type_id): array
    {
        $this->type_parents ??= ProductType::query()->pluck('parent_id', 'id')->all();
        $chain = [];

        while ($type_id !== null && array_key_exists($type_id, $this->type_parents)
            && !in_array($type_id, $chain, true)) {
            $chain[] = $type_id;
            $type_id = $this->type_parents[$type_id];
        }

        return $chain;
    }
}

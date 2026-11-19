<?php

namespace App\Rules;

use App\Domain\Pricing\NodeCatalog;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

/**
 * A price formula's graph as the calculator can work it out: blocks it knows,
 * settings it can read, wires joining an output to an input of the same kind,
 * at most one of each result, and no wire that leads back to where it came from.
 *
 * Each problem names the block it is about, so it can be found on the canvas.
 */
class PriceFormulaGraph implements ValidationRule
{
    private const MAX_NODES = 200;

    private const MAX_EDGES = 500;

    private const MAX_NUMBER = 1_000_000_000;

    private const MAX_TITLE = 100;

    private const EDGE_ENDS = ['source', 'sourceHandle', 'target', 'targetHandle'];

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        foreach ($this->problems($value) as $problem) {
            $fail($problem);
        }
    }

    /** @return array<int, string> */
    public function problems(mixed $graph): array
    {
        if (!is_array($graph) || !is_array($graph['nodes'] ?? null) || !is_array($graph['edges'] ?? [])) {
            return ['De formule is niet te lezen.'];
        }

        $nodes = array_values($graph['nodes']);
        $edges = array_values($graph['edges'] ?? []);

        if (count($nodes) > self::MAX_NODES || count($edges) > self::MAX_EDGES) {
            return ['Een formule mag hoogstens ' . self::MAX_NODES . ' blokken en '
                . self::MAX_EDGES . ' lijnen hebben.'];
        }

        $problems = [];
        $definitions = [];
        $references = array_fill_keys(array_keys(NodeCatalog::REFERENCES), []);

        foreach ($nodes as $node) {
            $id = $node['id'] ?? null;
            $definition = is_array($node) ? NodeCatalog::definition((string) ($node['type'] ?? '')) : null;

            if (!is_string($id) || $id === '' || strlen($id) > 64 || $definition === null) {
                $problems[] = 'Er staat een onbekend blok in de formule.';

                continue;
            }

            if (isset($definitions[$id])) {
                $problems[] = 'Twee blokken hebben dezelfde naam.';

                continue;
            }

            $definitions[$id] = $definition;
            $name = "Blok '" . $definition['label'] . "'";

            if (!is_numeric($node['position']['x'] ?? null) || !is_numeric($node['position']['y'] ?? null)) {
                $problems[] = $name . ' heeft geen plek op het canvas.';
            }

            $data = $node['data'] ?? [];

            if (!is_array($data)) {
                $problems[] = $name . ' heeft onleesbare instellingen.';

                continue;
            }

            if (isset($data['title']) && (!is_string($data['title']) || mb_strlen($data['title']) > self::MAX_TITLE)) {
                $problems[] = $name . ': de naam op het blok mag hoogstens ' . self::MAX_TITLE . ' tekens hebben.';
            }

            foreach ($definition['fields'] as $field) {
                if (!array_key_exists($field['key'], $data) || $data[$field['key']] === null) {
                    continue;
                }

                $problem = $this->fieldProblem($field, $data[$field['key']], $references);

                if ($problem !== null) {
                    $problems[] = $name . ': ' . $problem;
                }
            }
        }

        foreach (['output_periodic', 'output_one_time'] as $result) {
            $count = count(array_filter($nodes, fn ($node) => ($node['type'] ?? null) === $result));

            if ($count > 1) {
                $problems[] = "Er mag maar één blok '" . NodeCatalog::definition($result)['label']
                    . "' in de formule staan.";
            }
        }

        $problems = array_merge($problems, $this->edgeProblems($edges, $definitions), $this->missing($references));

        if ($problems === [] && $this->hasCycle($edges)) {
            $problems[] = 'Een lijn loopt in een kring terug naar het blok waar hij begint. Haal een van die lijnen weg.';
        }

        return array_values(array_unique($problems));
    }

    /**
     * What is wrong with one setting, or null. The records a setting points at
     * are collected in $references and looked up all at once afterwards.
     */
    private function fieldProblem(array $field, mixed $value, array &$references): ?string
    {
        $label = $field['label'];

        if (isset(NodeCatalog::REFERENCES[$field['type']])) {
            if (!is_numeric($value)) {
                return $label . ' is onbekend.';
            }

            $references[$field['type']][] = $value;

            return null;
        }

        return match ($field['type']) {
            'number' => is_numeric($value) && abs((float) $value) < self::MAX_NUMBER
                ? null
                : $label . ' moet een getal zijn.',
            'text' => is_string($value) && mb_strlen($value) <= 255 ? null : $label . ' is te lang.',
            'select' => is_scalar($value) && array_key_exists((string) $value, $field['options'])
                ? null
                : $label . ' heeft een ongeldige keuze.',
            'tiers' => $this->tiersProblem($value),
            'components' => $this->componentsProblem($value, $references),
            default => null,
        };
    }

    private function tiersProblem(mixed $steps): ?string
    {
        if (!is_array($steps) || count($steps) > 20) {
            return 'de staffel heeft hoogstens 20 stappen.';
        }

        foreach ($steps as $step) {
            $from = $step['from'] ?? null;

            $amount = $step['amount'] ?? null;

            if (!is_numeric($from) || (int) $from < 2 || (int) $from > self::MAX_NUMBER
                || !is_numeric($amount) || abs((float) $amount) >= self::MAX_NUMBER) {
                return 'elke stap van de staffel heeft een bedrag en een "vanaf stuk" van 2 of hoger.';
            }
        }

        return null;
    }

    private function componentsProblem(mixed $components, array &$references): ?string
    {
        if (!is_array($components) || count($components) > 10) {
            return 'een combinatie bestaat uit hoogstens 10 onderdelen.';
        }

        foreach ($components as $component) {
            $quantity = $component['quantity'] ?? null;

            if (!is_numeric($quantity) || (int) $quantity < 1 || (int) $quantity > 100) {
                return 'elk onderdeel van de combinatie heeft een aantal van 1 tot 100.';
            }

            if (!is_numeric($component['product_id'] ?? 0) || !is_numeric($component['product_type_id'] ?? 0)) {
                return 'een onderdeel van de combinatie heeft een onbekend type of model.';
            }

            if (!empty($component['product_id'])) {
                $references['product'][] = $component['product_id'];
            } elseif (!empty($component['product_type_id'])) {
                $references['product_type'][] = $component['product_type_id'];
            }
        }

        return null;
    }

    /** @return array<int, string> */
    private function edgeProblems(array $edges, array $definitions): array
    {
        $problems = [];
        $seen = [];

        foreach ($edges as $edge) {
            $ends = array_combine(self::EDGE_ENDS, array_map(
                fn (string $end) => is_array($edge) && is_string($edge[$end] ?? null) ? $edge[$end] : '',
                self::EDGE_ENDS
            ));
            $source = $definitions[$ends['source']] ?? null;
            $target = $definitions[$ends['target']] ?? null;

            if ($source === null || $target === null || $ends['source'] === $ends['target']) {
                $problems[] = 'Een lijn loopt naar een blok dat niet bestaat.';

                continue;
            }

            $key = implode('|', $ends);

            if (isset($seen[$key])) {
                $problems[] = "Er lopen twee lijnen van '" . $source['label'] . "' naar dezelfde ingang van '"
                    . $target['label'] . "'.";

                continue;
            }

            $seen[$key] = true;

            $output = collect($source['outputs'])->firstWhere('id', $ends['sourceHandle']);
            $input = collect($target['inputs'])->firstWhere('id', $ends['targetHandle']);

            if ($output === null || $input === null) {
                $problems[] = "De lijn van '" . $source['label'] . "' naar '" . $target['label']
                    . "' zit niet aan een ingang of uitgang vast.";
            } elseif ($output['kind'] !== $input['kind']) {
                $problems[] = "'" . $source['label'] . "' geeft " . $this->kindName($output['kind'])
                    . ", maar '" . $target['label'] . "' verwacht daar " . $this->kindName($input['kind']) . '.';
            }
        }

        return $problems;
    }

    /** @return array<int, string> */
    private function missing(array $references): array
    {
        $problems = [];

        foreach ($references as $type => $ids) {
            $ids = array_values(array_unique(array_map('intval', $ids)));

            if ($ids === []) {
                continue;
            }

            $found = DB::table(NodeCatalog::REFERENCES[$type]['table'])->whereIn('id', $ids)->count();

            if ($found < count($ids)) {
                $problems[] = 'Een gekozen ' . NodeCatalog::REFERENCES[$type]['noun'] . ' bestaat niet meer.';
            }
        }

        return $problems;
    }

    private function hasCycle(array $edges): bool
    {
        $next = [];
        foreach ($edges as $edge) {
            $next[$edge['source']][] = $edge['target'];
        }

        $state = [];
        $visit = function (string $id) use (&$visit, &$state, $next): bool {
            if (($state[$id] ?? 0) === 1) {
                return true;
            }
            if (($state[$id] ?? 0) === 2) {
                return false;
            }
            $state[$id] = 1;
            foreach ($next[$id] ?? [] as $target) {
                if ($visit((string) $target)) {
                    return true;
                }
            }
            $state[$id] = 2;

            return false;
        };

        foreach (array_keys($next) as $id) {
            if ($visit((string) $id)) {
                return true;
            }
        }

        return false;
    }

    private function kindName(string $kind): string
    {
        return $kind === NodeCatalog::MACHINES ? 'machines' : 'een getal';
    }
}

<?php

namespace App\Domain\Tools\Read;

use App\Domain\Pricing\CompactFormula;
use App\Domain\Pricing\ContractPricing;
use App\Domain\Tools\Concerns\BuildsPriceFormulas;
use App\Domain\Tools\Tool;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolProfile;
use App\Domain\Tools\ToolResult;
use App\Domain\Tools\Write\Concerns\ChecksEnums;
use App\Domain\Tools\Write\Concerns\ValidatesLikeTheForm;
use App\Enums\ContractInterval;
use App\Http\Requests\PriceFormulaPreviewRequest;
use App\Models\MaintenanceContract;
use App\Models\PriceFormula;
use App\Models\User;

/**
 * Works a draft formula out before anybody is asked to save it: on made-up
 * machines, or on a real contract. The assistant checks its own work here, and
 * can tell somebody what their description comes to for a customer like theirs.
 */
class TryPriceFormulaTool implements Tool
{
    use BuildsPriceFormulas;
    use ChecksEnums;
    use ValidatesLikeTheForm;

    public static function name(): string
    {
        return 'try_price_formula';
    }

    public function description(): string
    {
        return 'Rekent een prijsformule door op voorbeeldmachines of een contract, zonder op te slaan: de prijzen, '
            . 'wat elk blok oplevert, machines die niets kosten en fouten. Controleer er je formule mee.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                ...$this->formulaProperties(),
                'machines' => [
                    'type' => 'array',
                    'description' => 'Voorbeeldmachines: {"quantity": 3, "product_type" of "product": naam, '
                        . '"address": 1}. '
                        . 'Machines met hetzelfde adresnummer staan samen.',
                    'items' => ['type' => 'object'],
                ],
                'maintenance_contract_id' => [
                    'type' => 'integer',
                    'description' => 'Op dit contract proberen in plaats van op voorbeeldmachines.',
                ],
                'price_interval' => [
                    'type' => 'string',
                    'description' => 'Prijsinterval van het voorbeeld, zoals Jaarlijks.',
                ],
                'frequency' => ['type' => 'string', 'description' => 'Onderhoudsfrequentie van het voorbeeld.'],
                'inputs' => ['type' => 'object', 'description' => 'Waarden voor input-blokken, per blok-id.'],
            ],
            'required' => ['blocks', 'wires'],
            'additionalProperties' => false,
        ];
    }

    /** Trying it on a real contract shows that contract's money. */
    public function authorize(User $user, array $arguments): bool
    {
        return $user->can('tryOn', [
            PriceFormula::class,
            MaintenanceContract::find($arguments['maintenance_contract_id'] ?? null),
        ]);
    }

    public function requiresConfirmation(): bool
    {
        return false;
    }

    public static function difficulty(): int
    {
        return 6;
    }

    public static function availableTo(): array
    {
        return [ToolProfile::planner, ToolProfile::administrator];
    }

    public function execute(ToolCall $call): ToolResult
    {
        ['graph' => $graph, 'problems' => $problems] = $this->graphFrom(
            (array) $call->argument('blocks', []),
            (array) $call->argument('wires', []),
        );
        ['sample' => $sample, 'problems' => $sample_problems] = app(CompactFormula::class)
            ->sample((array) $call->argument('machines', []));
        $problems = [...$problems, ...$sample_problems];

        if ($problems !== []) {
            return ToolResult::failedWith(
                ['fouten' => $problems],
                'De formule klopt nog niet: ' . implode(' ', $problems)
            );
        }

        $trial = [
            'graph' => $graph,
            'sample' => $sample,
            'maintenance_contract_id' => $call->integerArgument('maintenance_contract_id'),
            'price_interval' => $this->interval($call->stringArgument('price_interval')),
            'frequency' => $this->interval($call->stringArgument('frequency')),
            'inputs' => (array) $call->argument('inputs', []),
        ];
        $validator = $this->asTheFormWould(
            PriceFormulaPreviewRequest::class,
            array_filter($trial, fn ($value) => $value !== null)
        );

        if ($validator->fails()) {
            return ToolResult::failed($this->complaintOf($validator));
        }

        $result = app(ContractPricing::class)->trial(
            $graph,
            MaintenanceContract::find($trial['maintenance_contract_id']),
            $sample,
            $trial['price_interval'],
            $trial['frequency'],
            $trial['inputs'],
        );

        return ToolResult::ok([
            'prijs_per_periode' => $result['periodic'],
            'eenmalig' => $result['one_time'],
            'machines' => $result['machine_count'],
            'kosten_niets' => $result['unpriced'],
            'blokken' => array_map(fn (array $node) => array_filter([
                'uit' => array_map(
                    fn (array $output) => $output['kind'] === 'machines'
                        ? $output['count'] . ' machines'
                        : $output['value'],
                    $node['outputs']
                ),
                'fout' => $node['error'],
            ]), $result['nodes']),
        ], 'Per periode ' . $this->euro($result['periodic']) . ', eenmalig ' . $this->euro($result['one_time']) . '.');
    }

    /** An interval as the contract form knows it; anything else goes to the form's check as it was given. */
    private function interval(?string $given): string
    {
        return $this->oneOf($given, ContractInterval::class, ContractInterval::jaarlijks->value) ?? (string) $given;
    }
}

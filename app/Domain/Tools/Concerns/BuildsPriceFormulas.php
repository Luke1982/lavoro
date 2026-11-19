<?php

namespace App\Domain\Tools\Concerns;

use App\Domain\Pricing\CompactFormula;
use App\Support\Money;

/**
 * How a price formula travels in a tool call: blocks and wires, as
 * price_formulas describes them. Trying one and saving one take the same shape,
 * so a draft that was tried is the draft that gets saved.
 */
trait BuildsPriceFormulas
{
    /** @return array<string, array<string, mixed>> */
    private function formulaProperties(): array
    {
        return [
            'blocks' => [
                'type' => 'array',
                'description' => 'De blokken, met een kort eigen id. Types en instellingen: zie price_formulas.',
                'items' => [
                    'type' => 'object',
                    'properties' => [
                        'id' => ['type' => 'string'],
                        'type' => ['type' => 'string'],
                        'title' => ['type' => 'string', 'description' => 'Naam op het blok, zoals "Airco per stuk".'],
                        'settings' => ['type' => 'object'],
                    ],
                    'required' => ['id', 'type'],
                ],
            ],
            'wires' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Lijnen als "blok.uitgang -> blok.ingang", zoals "filter.match -> aantal.machines".',
            ],
        ];
    }

    /**
     * @param  array<int, mixed>  $blocks
     * @param  array<int, mixed>  $wires
     * @return array{graph: array, problems: array<int, string>}
     */
    private function graphFrom(array $blocks, array $wires, ?array $previous = null): array
    {
        return app(CompactFormula::class)->toGraph($blocks, $wires, $previous);
    }

    private function euro(?float $amount): string
    {
        return $amount === null ? 'niets' : Money::euros($amount);
    }

    /** "één contract", "3 contracten": a count as a person would write it. */
    private function counted(int $count, string $one, string $many): string
    {
        return $count === 1 ? 'één ' . $one : $count . ' ' . $many;
    }
}

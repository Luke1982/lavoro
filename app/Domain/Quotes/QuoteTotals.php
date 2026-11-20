<?php

namespace App\Domain\Quotes;

use App\Models\QuoteLine;
use App\Support\Money;
use Illuminate\Support\Collection;

/**
 * What a quote adds up to, worked out in cents so the lines and the total never
 * disagree by a cent. VAT is counted per rate over the sum of its lines, the way
 * an invoice states it, not per line.
 *
 * A line goes where the line at its top goes: a product decides for the
 * materials under it. Optional lines the customer did not choose are what the
 * customer may still add: they are summed apart and are not in the total. An
 * alternative not chosen is neither: taking it means giving up another one.
 * Stelposten are in the total, and summed apart as well, because they are
 * settled afterwards. The cost and margin are only for whoever may see margins.
 */
final class QuoteTotals
{
    /**
     * @param  array<int, array{rate: float, net_cents: int, vat_cents: int}>  $vat  highest rate first
     */
    private function __construct(
        public readonly int $net_cents,
        public readonly array $vat,
        public readonly int $vat_cents,
        public readonly int $optional_net_cents,
        public readonly int $provisional_net_cents,
        public readonly int $cost_cents,
    ) {}

    /** @param Collection<int, QuoteLine> $lines */
    public static function of(Collection $lines): self
    {
        $by_id = $lines->keyBy('id');
        $top = fn (QuoteLine $line) => $by_id->get($line->parent_id) ?? $line;

        $included = $lines->filter(fn (QuoteLine $line) => $top($line)->isIncluded());
        $optional = $lines->filter(fn (QuoteLine $line) => !$top($line)->isIncluded() && $top($line)->is_optional);
        $provisional = $included->filter(fn (QuoteLine $line) => $line->is_provisional || $top($line)->is_provisional);

        $vat = $included
            ->groupBy(fn (QuoteLine $line) => (string) $line->vat_percent)
            ->map(fn (Collection $group) => self::vatRow($group))
            ->filter(fn (array $row) => $row['net_cents'] !== 0)
            ->sortByDesc('rate')
            ->values()
            ->all();

        return new self(
            self::net($included),
            $vat,
            array_sum(array_column($vat, 'vat_cents')),
            self::net($optional),
            self::net($provisional),
            $included->sum(fn (QuoteLine $line) => $line->costCents()),
        );
    }

    public function grossCents(): int
    {
        return $this->net_cents + $this->vat_cents;
    }

    /** Nothing with an amount on it, not even an option: nothing to say yes to. */
    public function isEmpty(): bool
    {
        return $this->net_cents === 0 && $this->optional_net_cents === 0;
    }

    /** @return array<string, mixed> in euros, for the screen */
    public function toArray(bool $with_margin): array
    {
        return [
            'net' => $this->net_cents / 100,
            'vat' => array_map(fn (array $row) => [
                'rate' => $row['rate'],
                'net' => $row['net_cents'] / 100,
                'vat' => $row['vat_cents'] / 100,
            ], $this->vat),
            'vat_total' => $this->vat_cents / 100,
            'gross' => $this->grossCents() / 100,
            'optional_net' => $this->optional_net_cents / 100,
            'provisional_net' => $this->provisional_net_cents / 100,
            ...($with_margin ? [
                'cost' => $this->cost_cents / 100,
                'margin' => ($this->net_cents - $this->cost_cents) / 100,
            ] : []),
        ];
    }

    public function describe(): string
    {
        return "\u{20AC}" . Money::human($this->net_cents) . ' excl. btw';
    }

    /** @param Collection<int, QuoteLine> $lines */
    private static function net(Collection $lines): int
    {
        return $lines->sum(fn (QuoteLine $line) => $line->netCents());
    }

    /**
     * @param  Collection<int, QuoteLine>  $group  lines of one rate
     * @return array{rate: float, net_cents: int, vat_cents: int}
     */
    private static function vatRow(Collection $group): array
    {
        $rate = $group->first()->vat_percent;
        $net = self::net($group);

        return ['rate' => $rate, 'net_cents' => $net, 'vat_cents' => (int) round($net * $rate / 100)];
    }
}

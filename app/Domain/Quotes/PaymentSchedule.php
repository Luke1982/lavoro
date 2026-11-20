<?php

namespace App\Domain\Quotes;

use App\Support\Quantity;

/**
 * When the customer pays what, in terms that add up to the whole: "30% bij
 * opdracht, 70% na oplevering". A term marked before_start is paid before the
 * work begins. The installation terms cap that for a private customer (AVIC 25%,
 * NVKL 35%, the law 50%); the settings hold the cap the company works under.
 */
final class PaymentSchedule
{
    /** @return array<string, array<int, mixed>> the rules for a schedule sent along as payment_schedule */
    public static function rules(): array
    {
        return [
            'payment_schedule' => ['sometimes', 'nullable', 'array', 'max:10'],
            'payment_schedule.*.description' => ['required', 'string', 'max:255'],
            'payment_schedule.*.percent' => ['required', 'numeric', 'gt:0', 'max:100'],
            'payment_schedule.*.before_start' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * What is wrong with this schedule for this customer, in words to act on;
     * null when nothing is. No schedule at all is fine: then nothing is agreed
     * beyond the payment terms.
     *
     * @param  array<int, array<string, mixed>>|null  $terms
     */
    public static function problem(?array $terms, bool $is_consumer): ?string
    {
        if (empty($terms)) {
            return null;
        }

        $total = array_sum(array_map(fn (array $term) => (float) $term['percent'], $terms));

        if (abs($total - 100) > 0.001) {
            return 'De betaaltermijnen tellen op tot ' . self::percent($total) . ' in plaats van 100%.';
        }

        $upfront = self::upfront($terms);
        $cap = QuoteSettings::maxUpfrontPercent();

        if ($is_consumer && $upfront > $cap) {
            return 'Van een particulier mag je vóór de start ten hoogste ' . self::percent($cap)
                . ' vragen; deze termijnen vragen ' . self::percent($upfront) . '.';
        }

        return null;
    }

    /** @param array<int, array<string, mixed>> $terms */
    public static function upfront(array $terms): float
    {
        return array_sum(array_map(
            fn (array $term) => !empty($term['before_start']) ? (float) $term['percent'] : 0.0,
            $terms,
        ));
    }

    /**
     * The terms as stored: only what is asked, in the order given.
     *
     * @param  array<int, array<string, mixed>>|null  $terms
     * @return array<int, array{description: string, percent: float, before_start: bool}>|null
     */
    public static function normalize(?array $terms): ?array
    {
        if (empty($terms)) {
            return null;
        }

        return array_values(array_map(fn (array $term) => [
            'description' => trim((string) $term['description']),
            'percent' => round((float) $term['percent'], 2),
            'before_start' => (bool) ($term['before_start'] ?? false),
        ], $terms));
    }

    /**
     * Each term with its amount, for the customer: "30% bij opdracht: € 1.234,50".
     *
     * @param  array<int, array<string, mixed>>|null  $terms
     * @return array<int, array<string, mixed>>
     */
    public static function withAmounts(?array $terms, int $gross_cents): array
    {
        return array_map(fn (array $term) => [
            ...$term,
            'amount' => round($gross_cents * (float) $term['percent'] / 100) / 100,
        ], $terms ?? []);
    }

    /** How the trail says it: "30% bij opdracht (vóór de start), 70% na oplevering". */
    public static function describe(?array $terms): ?string
    {
        if (empty($terms)) {
            return null;
        }

        return implode(', ', array_map(
            fn (array $term) => self::percent((float) $term['percent']) . ' ' . $term['description']
                . (!empty($term['before_start']) ? ' (vóór de start)' : ''),
            $terms,
        ));
    }

    private static function percent(float $value): string
    {
        return Quantity::human($value) . '%';
    }
}

<?php

namespace App\Domain\Assistant\Evals;

use App\Support\Money;

/**
 * Money as the eval reads and writes it: compared in whole cents, so a formula
 * that charges 245,50 is not told it charged 245,49999, and printed the one way.
 */
final class Euro
{
    public static function cents(mixed $amount): ?int
    {
        return Money::cents($amount);
    }

    /** What a run cost, from the micro-euros the meter counts in. */
    public static function micros(int $micros, int $decimals = 3): string
    {
        return '€' . number_format($micros / 1_000_000, $decimals, ',', '.');
    }

    public static function ofCents(?int $cents): string
    {
        return $cents === null ? '(geen bedrag)' : '€' . number_format($cents / 100, 2, ',', '.');
    }

    /** Whether an amount appears in Dutch writing: 1.234,50 or 1234,50, or 1234 when it is whole. */
    public static function mentionedIn(string $text, int $cents): bool
    {
        $amount = $cents / 100;
        $forms = [number_format($amount, 2, ',', '.'), number_format($amount, 2, ',', '')];

        if ($cents % 100 === 0) {
            $forms[] = number_format($amount, 0, ',', '.');
            $forms[] = (string) intdiv($cents, 100);
        }

        foreach (array_unique($forms) as $form) {
            if (preg_match('/(?<![\d.,])' . preg_quote($form, '/') . '(?![\d]|,\d)/', $text)) {
                return true;
            }
        }

        return false;
    }
}

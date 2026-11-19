<?php

namespace App\Domain\Products;

/**
 * The number behind a numeric kenmerk value. A person types "1,5", "1.5" or
 * "1,5 kW"; the number is stored on its own so it can be compared, and the
 * value a person reads is written from it with the unit of the kenmerk.
 *
 * At most twelve digits before the separator and six after it: what the
 * column holds, so nothing typed is rounded away in silence.
 */
final class AttributeNumber
{
    /**
     * The number in what was typed, or null when it is not one. Behind the
     * number only the unit of the kenmerk may follow.
     */
    public static function parse(string $input, ?string $unit = null): ?float
    {
        if (!preg_match('/^\s*(-?\d{1,12}(?:[.,]\d{1,6})?)\s*(.*?)\s*$/u', $input, $match)) {
            return null;
        }

        $rest = mb_strtolower($match[2]);

        if ($rest !== '' && $rest !== mb_strtolower(trim((string) $unit))) {
            return null;
        }

        return (float) str_replace(',', '.', $match[1]);
    }

    /** "1,5 kW": the number with a decimal comma, followed by the unit. */
    public static function label(float $number, ?string $unit = null): string
    {
        return blank($unit) ? self::format($number) : self::format($number) . ' ' . trim($unit);
    }

    /** "1,5": a decimal comma, and no zeros behind the last digit that counts. */
    public static function format(float $number): string
    {
        $text = rtrim(rtrim(number_format($number, 6, ',', ''), '0'), ',');

        return $text === '-0' ? '0' : $text;
    }
}

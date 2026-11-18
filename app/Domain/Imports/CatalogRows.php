<?php

namespace App\Domain\Imports;

/**
 * The rows of a sheet as values for the fields they were mapped to: text trimmed,
 * prices read the way a Dutch or an English list writes them, the markup applied,
 * and a row that repeats an earlier one in the same file set aside. Nothing here
 * looks at what is already in the database except the lookup names, which only
 * decide whether two rows are the same product.
 */
class CatalogRows
{
    private const MAX_PRICE = 99999999.99;

    /**
     * @return array<int, array{
     *     line: int,
     *     values: array<string, mixed>,
     *     calculated: array<int, string>,
     *     warnings: array<int, string>,
     *     skip: ?string,
     * }>
     */
    public static function read(ImportSheet $sheet, CatalogPlan $plan): array
    {
        $target = $plan->target();
        $settings = $plan->settings();
        $fields = $plan->fields();
        $columns = array_intersect_key($settings['columns'], $fields);
        $rows = [];
        $seen = [];

        foreach ($sheet->rows($settings['header_row']) as $line => $cells) {
            $values = [];
            $warnings = [];

            foreach ($columns as $field => $column) {
                $value = self::value($fields[$field], $cells[$column] ?? null, $warnings);

                if ($value !== null) {
                    $values[$field] = $value;
                }
            }

            if ($values === []) {
                continue;
            }

            $calculated = self::applyMarkup($target, $settings, $values);
            $key = $target->fileKey($values, $settings, $plan->supplier(), $plan->lookups());
            $skip = null;

            if ($key !== null && isset($seen[$key])) {
                $skip = 'Staat al op regel ' . $seen[$key] . ' van dit bestand.';
            } elseif ($key !== null) {
                $seen[$key] = $line;
            }

            $rows[] = [
                'line' => $line,
                'values' => $values,
                'calculated' => $calculated,
                'warnings' => $warnings,
                'skip' => $skip,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $field
     * @param  array<int, string>  $warnings
     */
    private static function value(array $field, mixed $cell, array &$warnings): string|float|null
    {
        if ($field['type'] === 'price') {
            return self::price($field['label'], $cell, $warnings);
        }

        $text = ImportSheet::text($cell);

        if ($text === null || $field['type'] === 'longtext') {
            return $text;
        }

        if (mb_strlen($text) > 255) {
            $warnings[] = $field['label'] . ' is ingekort tot 255 tekens.';

            return mb_substr($text, 0, 255);
        }

        return $text;
    }

    /**
     * A price as a number. A cell Excel already holds as a number is taken as it
     * is; text is read by its last separator, so "1.234,56", "1,234.56", "12,5"
     * and "€ 12.50" all come out right. Anything else is left empty and said.
     *
     * @param  array<int, string>  $warnings
     */
    private static function price(string $label, mixed $cell, array &$warnings): ?float
    {
        if (is_int($cell) || is_float($cell)) {
            $amount = (float) $cell;
        } else {
            $text = ImportSheet::text($cell);

            if ($text === null) {
                return null;
            }

            $amount = self::parseAmount($text);

            if ($amount === null) {
                $warnings[] = $label . ' ‘' . mb_strimwidth($text, 0, 30, '…') . '’ is geen bedrag en is overgeslagen.';

                return null;
            }
        }

        if ($amount < 0 || $amount > self::MAX_PRICE) {
            $warnings[] = $label . ' ' . self::money($amount) . ' kan niet en is overgeslagen.';

            return null;
        }

        return round($amount, 2);
    }

    private static function parseAmount(string $text): ?float
    {
        $clean = preg_replace('/[^\d,.\-]/u', '', $text) ?? '';

        if ($clean === '' || !preg_match('/\d/', $clean)) {
            return null;
        }

        $last_comma = strrpos($clean, ',');
        $last_dot = strrpos($clean, '.');
        $decimal = match (true) {
            $last_comma === false && $last_dot === false => null,
            $last_comma === false => '.',
            $last_dot === false => ',',
            default => $last_comma > $last_dot ? ',' : '.',
        };

        if ($decimal !== null && substr_count($clean, $decimal) > 1) {
            $clean = str_replace($decimal, '', $clean);
            $decimal = null;
        }

        if ($decimal !== null) {
            $thousands = $decimal === ',' ? '.' : ',';
            $clean = str_replace($thousands, '', $clean);
            $clean = str_replace($decimal, '.', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    /**
     * The sale price from the cost price and the markup, for a row that does not
     * carry a sale price of its own.
     *
     * @param  array<string, mixed>  $values
     * @return array<int, string> the fields that were worked out rather than read
     */
    private static function applyMarkup(CatalogTarget $target, array $settings, array &$values): array
    {
        $markup = $settings['markup_percent'];
        $cost = $values[$target->costField()] ?? null;

        if ($markup === null || $cost === null || isset($values[$target->saleField()])) {
            return [];
        }

        $values[$target->saleField()] = min(round($cost * (1 + $markup / 100), 2), self::MAX_PRICE);

        return [$target->saleField()];
    }

    public static function money(float $amount): string
    {
        return '€ ' . number_format($amount, 2, ',', '.');
    }
}

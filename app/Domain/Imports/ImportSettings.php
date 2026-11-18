<?php

namespace App\Domain\Imports;

/**
 * The choices made on the mapping screen, in the one shape everything else reads:
 * which tab and header row, which column fills which field, the fixed values for
 * new records, which fields may overwrite an existing record, what an existing
 * record is recognised by, the supplier and the markup.
 */
final class ImportSettings
{
    /**
     * @return array{
     *     sheet: ?string,
     *     header_row: int,
     *     supplier_id: ?int,
     *     markup_percent: ?float,
     *     columns: array<string, string>,
     *     fixed: array<string, int>,
     *     update: array<string, bool>,
     *     match_keys: ?array<int, string>,
     * }
     */
    public static function normalize(?array $settings): array
    {
        $settings ??= [];

        return [
            'sheet' => $settings['sheet'] ?? null,
            'header_row' => max(1, (int) ($settings['header_row'] ?? 1)),
            'supplier_id' => isset($settings['supplier_id']) ? (int) $settings['supplier_id'] : null,
            'markup_percent' => isset($settings['markup_percent']) && $settings['markup_percent'] !== ''
                ? (float) $settings['markup_percent']
                : null,
            'columns' => array_filter(
                array_map(fn ($column) => is_string($column) ? $column : null, $settings['columns'] ?? []),
            ),
            'fixed' => array_map('intval', array_filter($settings['fixed'] ?? [])),
            'update' => array_map('boolval', $settings['update'] ?? []),
            'match_keys' => is_array($settings['match_keys'] ?? null)
                ? array_values(array_filter($settings['match_keys'], 'is_string'))
                : null,
        ];
    }
}

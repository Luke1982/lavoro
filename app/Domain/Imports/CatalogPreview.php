<?php

namespace App\Domain\Imports;

use App\Models\CatalogImport;

/**
 * The check before an import is confirmed: how many rows become new records,
 * change one, stay as they are or are skipped, which brands, types, categories
 * or units would be made along the way, and the rows themselves. Rows that stay
 * the same are only counted; a price list read in for the tenth time is mostly
 * those, and listing them would bury the few that change.
 */
class CatalogPreview
{
    public const SHOWN_ROWS = 500;

    public static function build(CatalogImport $import, ImportSheet $sheet): array
    {
        $plan = CatalogPlan::for($import);
        $planned = $plan->plan(CatalogRows::read($sheet, $plan));

        $counts = ['create' => 0, 'update' => 0, 'unchanged' => 0, 'skip' => 0];
        $new_lookups = [];
        $listed = [];

        foreach ($planned as $row) {
            $counts[$row['action']]++;

            foreach (self::newLookups($plan, $row) as $label => $name) {
                $new_lookups[$label][mb_strtolower($name)] ??= $name;
            }

            if ($row['action'] !== 'unchanged') {
                $listed[] = array_diff_key($row, array_flip(['attributes', 'link_supplier', 'supplier_article']));
            }
        }

        return [
            'counts' => $counts,
            'total' => count($planned),
            'match_description' => $plan->target()->matchDescription($plan->settings(), $plan->supplier()),
            'new_lookups' => array_map(
                fn (string $label, array $names) => ['label' => $label, 'names' => array_values($names)],
                array_keys($new_lookups),
                array_values($new_lookups),
            ),
            'rows' => array_slice($listed, 0, self::SHOWN_ROWS),
            'hidden_rows' => max(0, count($listed) - self::SHOWN_ROWS),
        ];
    }

    /** @return array<string, string> field label => name about to be made */
    private static function newLookups(CatalogPlan $plan, array $row): array
    {
        $made = [];

        foreach ($plan->fields() as $definition) {
            $value = $row['attributes'][$definition['attribute'] ?? ''] ?? null;

            if ($definition['type'] === 'lookup' && is_array($value)) {
                $made[$definition['label']] = $value['new'];
            }
        }

        return $made;
    }
}

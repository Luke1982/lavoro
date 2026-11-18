<?php

namespace App\Domain\Imports;

use App\Models\CatalogImport;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * What an import will do with each row: make a new record, change an existing
 * one, leave it as it is, or skip it with the reason. The preview shows exactly
 * this, and the job writes exactly this, worked out again just before writing so
 * that what was made earlier in the same import is found instead of made twice.
 *
 * On an existing record a field is only overwritten when the mapping says so:
 * by default the prices, codes and description follow the list, while the name
 * and the classification someone may have tidied up by hand stay. A fixed value
 * chosen on the mapping screen is for new records only.
 */
class CatalogPlan
{
    private CatalogLookups $lookups;

    /** @var array<string, array<string, mixed>> */
    private array $fields;

    public function __construct(
        private CatalogTarget $target,
        private array $settings,
        private ?Supplier $supplier,
        User $user,
    ) {
        $this->fields = $target->fields($user);
        $this->lookups = $target->lookups($user);
    }

    public static function for(CatalogImport $import): self
    {
        $settings = ImportSettings::normalize($import->settings);

        return new self(
            $import->kind->target(),
            $settings,
            $settings['supplier_id'] ? Supplier::find($settings['supplier_id']) : null,
            $import->user,
        );
    }

    public function target(): CatalogTarget
    {
        return $this->target;
    }

    public function settings(): array
    {
        return $this->settings;
    }

    public function supplier(): ?Supplier
    {
        return $this->supplier;
    }

    public function lookups(): CatalogLookups
    {
        return $this->lookups;
    }

    /** @return array<string, array<string, mixed>> */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows  as CatalogRows reads them
     * @return array<int, array<string, mixed>>
     */
    public function plan(array $rows): array
    {
        $rows = array_values($rows);
        $matches = $this->target->match($rows, $this->settings, $this->supplier, $this->lookups);
        $ids = array_values(array_filter(array_map(fn (array $match) => $match['record']?->id, $matches)));
        $links = $this->supplier ? $this->target->supplierLinks($this->supplier, $ids) : [];

        return array_map(
            fn (int $index) => $this->planRow($rows[$index], $matches[$index], $links),
            array_keys($rows),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array{record: ?Model, matched_on: ?string, ambiguous: ?string}  $match
     * @param  array<int, ?string>  $links
     */
    private function planRow(array $row, array $match, array $links): array
    {
        $planned = [
            'line' => $row['line'],
            'action' => 'skip',
            'label' => $this->rowLabel($row['values']),
            'record_id' => null,
            'matched_on' => null,
            'reason' => null,
            'warnings' => $row['warnings'],
            'changes' => [],
            'details' => [],
            'attributes' => [],
            'link_supplier' => false,
            'supplier_article' => null,
        ];

        if ($row['skip'] !== null) {
            return [...$planned, 'reason' => $row['skip']];
        }

        if ($match['ambiguous'] !== null) {
            return [...$planned, 'reason' => $match['ambiguous']];
        }

        return $match['record']
            ? $this->planUpdate($planned, $row, $match, $links)
            : $this->planCreate($planned, $row);
    }

    private function planCreate(array $planned, array $row): array
    {
        $primary = $this->target->primaryField();

        if (!isset($row['values'][$primary])) {
            return [...$planned, 'reason' => 'Geen bestaand ' . $this->target->noun() . ' gevonden, en zonder '
                . mb_strtolower($this->fields[$primary]['label']) . ' wordt er geen nieuw gemaakt.'];
        }

        foreach ($this->fields as $field => $definition) {
            if (!isset($definition['attribute'])) {
                continue;
            }

            $value = $definition['type'] === 'lookup'
                ? $this->lookupValue($field, $row['values'][$field] ?? null) ?? ($this->settings['fixed'][$field] ?? null)
                : ($row['values'][$field] ?? null);

            if ($value === null) {
                if ($definition['required'] ?? false) {
                    return [...$planned, 'reason' => $definition['label'] . ' ontbreekt op deze regel.'];
                }

                continue;
            }

            $planned['attributes'][$definition['attribute']] = $value;

            if ($field !== $primary) {
                $planned['details'][] = [
                    'label' => $definition['label'],
                    'value' => $this->display($field, $value, in_array($field, $row['calculated'], true)),
                ];
            }
        }

        if ($this->supplier) {
            $planned['link_supplier'] = true;
            $planned['supplier_article'] = $row['values']['article_number'] ?? null;
            $planned['details'][] = [
                'label' => 'Leverancier',
                'value' => $this->supplierDisplay($planned['supplier_article']),
            ];
        }

        return [...$planned, 'action' => 'create'];
    }

    private function planUpdate(array $planned, array $row, array $match, array $links): array
    {
        $record = $match['record'];
        $planned['record_id'] = $record->id;
        $planned['matched_on'] = $match['matched_on'];
        $planned['label'] = $this->target->label($record);

        foreach ($this->fields as $field => $definition) {
            if (!isset($definition['attribute']) || !isset($row['values'][$field]) || !$this->updates($field)) {
                continue;
            }

            $attribute = $definition['attribute'];
            $old = $record->getAttribute($attribute);
            $new = $definition['type'] === 'lookup'
                ? $this->lookupValue($field, $row['values'][$field])
                : $row['values'][$field];

            if ($this->same($definition['type'], $old, $new)) {
                continue;
            }

            $planned['attributes'][$attribute] = $new;
            $planned['changes'][] = [
                'label' => $definition['label'],
                'from' => $old === null ? '—' : $this->display($field, $old),
                'to' => $this->display($field, $new, in_array($field, $row['calculated'], true)),
            ];
        }

        if ($this->supplier) {
            $article = $row['values']['article_number'] ?? null;
            $linked = array_key_exists($record->id, $links);

            if (!$linked || ($article !== null && $article !== $links[$record->id])) {
                $planned['link_supplier'] = true;
                $planned['supplier_article'] = $article ?? $links[$record->id] ?? null;
                $planned['changes'][] = [
                    'label' => 'Leverancier',
                    'from' => $linked ? $this->supplierDisplay($links[$record->id]) : '—',
                    'to' => $this->supplierDisplay($planned['supplier_article']),
                ];
            }
        }

        return [...$planned, 'action' => $planned['changes'] === [] ? 'unchanged' : 'update'];
    }

    /**
     * What a row is called in the preview before it is matched: its name, or for
     * a row without one the first number it could be recognised by, so a skipped
     * row can still be found back in the sheet.
     *
     * @param  array<string, mixed>  $values
     */
    private function rowLabel(array $values): ?string
    {
        foreach ([$this->target->primaryField(), ...$this->target->matchKeyNames()] as $field) {
            if (isset($values[$field])) {
                return (string) $values[$field];
            }
        }

        return null;
    }

    private function updates(string $field): bool
    {
        return $this->settings['update'][$field] ?? $this->fields[$field]['updates'] ?? true;
    }

    /**
     * The id of a name in a lookup list, or the name itself marked as new when
     * the list does not have it yet; the writer makes it on the way in.
     *
     * @return int|array{new: string}|null
     */
    private function lookupValue(string $field, ?string $name): int|array|null
    {
        if ($name === null) {
            return null;
        }

        return $this->lookups->idFor($field, $name) ?? ['new' => $name];
    }

    private function same(string $type, mixed $old, mixed $new): bool
    {
        return match ($type) {
            'price' => $old !== null && round((float) $old, 2) === round((float) $new, 2),
            'lookup' => is_int($new) && (int) $old === $new,
            default => $old !== null && (string) $old === (string) $new,
        };
    }

    private function display(string $field, mixed $value, bool $calculated = false): string
    {
        $type = $this->fields[$field]['type'];

        if ($type === 'lookup') {
            return is_array($value)
                ? $value['new'] . ' (nieuw)'
                : ($this->lookups->nameOf($field, (int) $value) ?? '—');
        }

        if ($type === 'price') {
            $money = CatalogRows::money((float) $value);

            if (!$calculated) {
                return $money;
            }

            $cost_label = mb_strtolower($this->fields[$this->target->costField()]['label']);
            $markup = rtrim(rtrim(number_format($this->settings['markup_percent'], 2, ',', ''), '0'), ',');

            return $money . ' (' . $cost_label . ' + ' . $markup . '%)';
        }

        return mb_strimwidth((string) $value, 0, 80, '…');
    }

    private function supplierDisplay(?string $article): string
    {
        return $article === null
            ? $this->supplier->name
            : $this->supplier->name . ', artikelnummer ' . $article;
    }
}

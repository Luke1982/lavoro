<?php

namespace App\Domain\Imports;

use App\Enums\CatalogImportKind;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * What a spreadsheet import writes into: the fields a column can fill, how an
 * existing record is recognised, and how a new one comes into being. One per
 * kind; everything else in an import is the same for materials and products.
 *
 * A field is described by:
 *   - label: what the mapping screen calls it;
 *   - type: text, longtext, price, or lookup (a name in another list);
 *   - attribute: the column it writes, absent for the supplier's article number;
 *   - lookup: the model a lookup field names;
 *   - required: a new record cannot be made without it;
 *   - financial: only for whoever may see prices;
 *   - supplier: only meaningful with a supplier chosen;
 *   - updates: whether it overwrites an existing record unless told otherwise.
 */
abstract class CatalogTarget
{
    abstract public function kind(): CatalogImportKind;

    /** @return array<string, array<string, mixed>> */
    abstract protected function allFields(): array;

    abstract protected function canSeePrices(User $user): bool;

    /** The field a record is named by, and without which no new record is made. */
    abstract public function primaryField(): string;

    abstract public function costField(): string;

    abstract public function saleField(): string;

    abstract public function noun(): string;

    abstract public function plural(): string;

    /** @param  array<string, mixed>  $attributes */
    abstract public function create(array $attributes): Model;

    abstract public function label(Model $record): string;

    /**
     * The keys an existing record can be recognised by, best first. Each is named
     * after the field it reads, so a key whose column is not mapped is never tried.
     *
     * @return array<string, string> key => how the preview says it, with its article
     */
    abstract protected function matchKeys(?Supplier $supplier): array;

    /**
     * The value a row has for a key, as it is compared: lowercased text.
     *
     * @param  array<string, mixed>  $values
     */
    abstract protected function keyValue(string $key, array $values, array $settings, CatalogLookups $lookups): ?string;

    /**
     * The records whose value for a key is one of these, as lowercased value => ids.
     *
     * @param  array<int, string>  $values
     * @return array<string, array<int, int>>
     */
    abstract protected function recordsByKey(string $key, array $values, ?Supplier $supplier): array;

    /** @return array<string, array<string, mixed>> */
    public function fields(User $user): array
    {
        $can_see_prices = $this->canSeePrices($user);

        return array_filter(
            $this->allFields(),
            fn (array $field) => $can_see_prices || !($field['financial'] ?? false),
        );
    }

    /**
     * The fields this person may not map, so a mapping they save keeps what
     * someone with more rights chose for those.
     *
     * @return array<int, string>
     */
    public function hiddenFieldKeys(User $user): array
    {
        return array_keys(array_diff_key($this->allFields(), $this->fields($user)));
    }

    /** @return array<string, array<string, mixed>> */
    public function lookupFields(User $user): array
    {
        return array_filter($this->fields($user), fn (array $field) => $field['type'] === 'lookup');
    }

    public function lookups(User $user): CatalogLookups
    {
        return new CatalogLookups(array_map(fn (array $field) => $field['lookup'], $this->lookupFields($user)));
    }

    /**
     * The fields as the mapping screen offers them.
     *
     * @return array<int, array<string, mixed>>
     */
    public function fieldOptions(User $user): array
    {
        $fields = $this->fields($user);

        return array_values(array_map(
            fn (string $key, array $field) => [
                'key' => $key,
                'label' => $field['label'],
                'type' => $field['type'],
                'required' => $field['required'] ?? false,
                'supplier' => $field['supplier'] ?? false,
                'updates' => $field['updates'] ?? true,
                'is_primary' => $key === $this->primaryField(),
                'is_cost' => $key === $this->costField(),
                'options' => $field['type'] === 'lookup'
                    ? $field['lookup']::query()->orderBy('name')->get(['id', 'name'])->toArray()
                    : [],
            ],
            array_keys($fields),
            $fields,
        ));
    }

    /**
     * The key two rows in one file are the same thing by: the first key the row
     * has a value for, in the order existing records are recognised.
     *
     * @param  array<string, mixed>  $values
     */
    public function fileKey(array $values, array $settings, ?Supplier $supplier, CatalogLookups $lookups): ?string
    {
        foreach (array_keys($this->activeMatchKeys($settings, $supplier)) as $key) {
            $value = $this->keyValue($key, $values, $settings, $lookups);

            if ($value !== null) {
                return $key . ':' . $value;
            }
        }

        return null;
    }

    /**
     * Which existing record each row is about. A row tries the keys in order and
     * stops at the first it has a value for that finds something; a value that
     * finds two records stops it too, because guessing which one is meant would
     * overwrite the wrong one.
     *
     * @param  array<int, array<string, mixed>>  $rows  each with 'values'
     * @return array<int, array{record: ?Model, matched_on: ?string, ambiguous: ?string}>
     */
    public function match(array $rows, array $settings, ?Supplier $supplier, CatalogLookups $lookups): array
    {
        $keys = $this->activeMatchKeys($settings, $supplier);
        $indexes = [];

        foreach (array_keys($keys) as $key) {
            $values = [];

            foreach ($rows as $row) {
                $value = $this->keyValue($key, $row['values'], $settings, $lookups);

                if ($value !== null) {
                    $values[$value] = true;
                }
            }

            $indexes[$key] = $values === [] ? [] : $this->recordsByKey($key, array_keys($values), $supplier);
        }

        $ids = collect($indexes)->flatten()->unique()->values()->all();
        $records = $ids === [] ? collect() : $this->kind()->modelClass()::query()->whereIn('id', $ids)->get()->keyBy('id');

        $matches = [];

        foreach ($rows as $index => $row) {
            $matches[$index] = ['record' => null, 'matched_on' => null, 'ambiguous' => null];

            foreach ($keys as $key => $description) {
                $value = $this->keyValue($key, $row['values'], $settings, $lookups);
                $found = $value === null ? [] : ($indexes[$key][$value] ?? []);

                if (count($found) > 1) {
                    $matches[$index]['ambiguous'] = 'Er zijn ' . count($found) . ' ' . $this->plural()
                        . ' die op ' . $description . ' passen; welke bedoeld is, is niet te zeggen.';
                    break;
                }

                if (count($found) === 1 && $records->has($found[0])) {
                    $matches[$index]['record'] = $records->get($found[0]);
                    $matches[$index]['matched_on'] = $description;
                    break;
                }
            }
        }

        return $matches;
    }

    /**
     * The sentence above the preview that says how existing records are found.
     */
    public function matchDescription(array $settings, ?Supplier $supplier): string
    {
        $keys = $this->activeMatchKeys($settings, $supplier);

        if ($keys === []) {
            return 'Bestaande ' . $this->plural() . ' worden nergens aan herkend: elke regel wordt een nieuw '
                . $this->noun() . ', ook als hij twee keer in het bestand staat.';
        }

        $descriptions = array_values($keys);
        $sentence = 'Bestaande ' . $this->plural() . ' worden herkend aan ' . array_shift($descriptions);

        foreach ($descriptions as $description) {
            $sentence .= ', anders aan ' . $description;
        }

        return $sentence . '.';
    }

    /**
     * What the mapping screen offers to recognise an existing record by, best
     * first.
     *
     * @return array<int, array{key: string, label: string}>
     */
    public function matchKeyOptions(): array
    {
        return array_map(
            fn (string $key) => ['key' => $key, 'label' => $this->matchKeyLabel($key)],
            $this->matchKeyNames(),
        );
    }

    /**
     * Every key a record can be recognised by, best first. The supplier's article
     * number is always among them; it only counts once a supplier is chosen.
     *
     * @return array<int, string>
     */
    public function matchKeyNames(): array
    {
        return ['article_number', ...array_keys($this->matchKeys(null))];
    }

    protected function matchKeyLabel(string $key): string
    {
        return $this->allFields()[$key]['label'];
    }

    /**
     * The keys this import recognises by: those ticked on the mapping screen, or
     * every one when nothing was chosen yet -- a mapping saved before there was a
     * choice -- and of those only the ones with a column to read.
     *
     * @return array<string, string> key => how the preview says it
     */
    protected function activeMatchKeys(array $settings, ?Supplier $supplier): array
    {
        $chosen = $settings['match_keys'] ?? null;

        return array_filter(
            $this->matchKeys($supplier),
            fn (string $key) => isset($settings['columns'][$key])
                && ($chosen === null || in_array($key, $chosen, true)),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Records of this kind whose column holds one of these values. The database
     * compares without case, the keys come back lowercased to compare with ours.
     *
     * @param  array<int, string>  $values
     * @return array<string, array<int, int>>
     */
    protected function byColumn(string $column, array $values): array
    {
        $index = [];

        foreach (array_chunk($values, 1000) as $chunk) {
            $this->kind()->modelClass()::query()
                ->whereIn($column, $chunk)
                ->get(['id', $column])
                ->each(function (Model $record) use (&$index, $column) {
                    $index[CatalogLookups::key((string) $record->getAttribute($column))][] = $record->id;
                });
        }

        return $index;
    }

    /**
     * Records linked to this supplier under one of these article numbers.
     *
     * @param  array<int, string>  $values
     * @return array<string, array<int, int>>
     */
    protected function bySupplierArticle(Supplier $supplier, array $values): array
    {
        $index = [];

        foreach (array_chunk($values, 1000) as $chunk) {
            DB::table('suppliables')
                ->where('supplier_id', $supplier->id)
                ->where('suppliable_type', $this->kind()->modelClass())
                ->whereIn('article_number', $chunk)
                ->get(['suppliable_id', 'article_number'])
                ->each(function ($link) use (&$index) {
                    $index[CatalogLookups::key((string) $link->article_number)][] = (int) $link->suppliable_id;
                });
        }

        return $index;
    }

    /**
     * How each of these records is linked to the supplier now: id => article
     * number, null for a link without one. A record missing here is not linked.
     *
     * @param  array<int, int>  $ids
     * @return array<int, ?string>
     */
    public function supplierLinks(Supplier $supplier, array $ids): array
    {
        if ($ids === []) {
            return [];
        }

        return DB::table('suppliables')
            ->where('supplier_id', $supplier->id)
            ->where('suppliable_type', $this->kind()->modelClass())
            ->whereIn('suppliable_id', $ids)
            ->pluck('article_number', 'suppliable_id')
            ->all();
    }

    /** @param  array<string, mixed>  $values */
    protected static function textKey(array $values, string $field): ?string
    {
        $value = $values[$field] ?? null;

        return $value === null ? null : CatalogLookups::key((string) $value);
    }
}

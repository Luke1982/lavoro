<?php

namespace App\Models\Traits;

use App\Domain\Signals\ModelChanged;
use App\Domain\Signals\Signals;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Gives a model an automatic audit trail. Adding this trait is all a model needs
 * to start logging who changed what and when, with the value before and after
 * every change, because Eloquent already tracks that and this reads it out.
 *
 * Every change is stored twice: the raw value, which is what a trace is analysed
 * on, and a readable label, which is what a person sees. Storing only the label
 * would mean a renamed customer silently rewrites history.
 *
 * Models refine the output with three optional properties:
 *
 *   protected array $activity_labels = ['customer_id' => 'Klant'];
 *   protected array $activity_relations = ['customer_id' => ['customer', 'name']];
 *   protected array $activity_ignore = ['internal_token'];
 *   protected array $activity_permissions = ['price' => 'material.see_financials'];
 *
 * @mixin Model
 *
 * @method static void created(callable $callback)
 * @method static void updated(callable $callback)
 * @method static void deleted(callable $callback)
 * @method static void registerModelEvent(string $event, callable $callback)
 */
trait RecordsHistory
{
    /** Fields no model ever logs, whatever its configuration says. */
    private static array $never_logged = [
        'created_at',
        'updated_at',
        'deleted_at',
        'password',
        'remember_token',
    ];

    public static function bootRecordsHistory(): void
    {
        static::created(fn (Model $model) => $model->recordHistory('created'));
        static::updated(fn (Model $model) => $model->recordHistory('updated'));
        static::deleted(fn (Model $model) => $model->recordHistory('deleted'));
        static::registerModelEvent('restored', fn (Model $model) => $model->recordHistory('restored'));
    }

    /**
     * Sensitive fields are split into their own entry rather than redacted, so
     * each entry's sentence is already right for whoever may read it and nobody
     * has to reconstruct a partial description at render time.
     */
    public function recordHistory(string $action): void
    {
        if ($action !== 'updated') {
            Signals::dispatch(new ModelChanged($this, $action));

            return;
        }

        $changes = $this->collectChanges();

        if ($changes === []) {
            return;
        }

        foreach ($this->groupByPermission($changes) as $permission => $group) {
            Signals::dispatch(new ModelChanged(
                $this,
                $action,
                $group,
                $permission === '' ? null : $permission,
            ));
        }
    }

    /**
     * The permission needed to read an entry reporting this field, or null when
     * anyone may. Public so a named signal reporting the same field gates itself
     * from the same declaration rather than repeating it.
     */
    public function permissionForField(string $field): ?string
    {
        $permissions = property_exists($this, 'activity_permissions') ? $this->activity_permissions : [];

        return $permissions[$field] ?? null;
    }

    /**
     * @param  array<int, array<string, mixed>>  $changes
     * @return array<string, array<int, array<string, mixed>>>
     */
    private function groupByPermission(array $changes): array
    {
        $grouped = [];

        foreach ($changes as $change) {
            $grouped[$this->permissionForField($change['field']) ?? ''][] = $change;
        }

        return $grouped;
    }

    /**
     * @return array<int, array{
     *     field: string, label: string,
     *     old_value: ?string, new_value: ?string,
     *     old_label: ?string, new_label: ?string
     * }>
     */
    private function collectChanges(): array
    {
        $collected = [];

        foreach ($this->getChanges() as $field => $new_raw) {
            if (!$this->shouldLogField($field)) {
                continue;
            }

            $old_raw = $this->getRawOriginal($field);

            if ($this->sameValue($old_raw, $new_raw)) {
                continue;
            }

            $collected[] = [
                'field' => $field,
                'label' => $this->fieldLabel($field),
                'old_value' => $this->scalarValue($old_raw),
                'new_value' => $this->scalarValue($new_raw),
                'old_label' => $this->displayValue($field, $old_raw),
                'new_label' => $this->displayValue($field, $new_raw),
            ];
        }

        return $collected;
    }

    private function sameValue(mixed $old, mixed $new): bool
    {
        if ($old === null || $new === null) {
            return $old === $new;
        }

        return (string) $old === (string) $new;
    }

    private function scalarValue(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return (string) $value;
    }

    private function shouldLogField(string $field): bool
    {
        if (in_array($field, self::$never_logged, true)) {
            return false;
        }

        if (in_array($field, $this->activityIgnored(), true)) {
            return false;
        }

        return !in_array($field, $this->getHidden(), true);
    }

    private function fieldLabel(string $field): string
    {
        $labels = property_exists($this, 'activity_labels') ? $this->activity_labels : [];

        if (isset($labels[$field])) {
            return $labels[$field];
        }

        return Str::ucfirst(str_replace('_', ' ', Str::beforeLast($field, '_id')));
    }

    /** @return array<int, string> */
    private function activityIgnored(): array
    {
        return property_exists($this, 'activity_ignore') ? $this->activity_ignore : [];
    }

    /**
     * The readable form of a stored value. Foreign keys become the name of the
     * thing they point at, which is the difference between a log that reads
     * "Klant gewijzigd naar Jansen BV" and one that reads "142".
     */
    private function displayValue(string $field, mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $relations = property_exists($this, 'activity_relations') ? $this->activity_relations : [];

        if (isset($relations[$field])) {
            [$relation_name, $display_column] = $relations[$field];

            return $this->resolveRelatedLabel($relation_name, $display_column, $value);
        }

        if (is_bool($value) || $this->hasCast($field, ['bool', 'boolean'])) {
            return $value ? 'Ja' : 'Nee';
        }

        if ($value instanceof \DateTimeInterface) {
            return $value->format('d-m-Y H:i');
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE);
        }

        return (string) $value;
    }

    private function resolveRelatedLabel(string $relation_name, string $display_column, mixed $id): ?string
    {
        if (!method_exists($this, $relation_name)) {
            return (string) $id;
        }

        $related = $this->{$relation_name}()->getRelated()->newQuery()->find($id);

        return $related?->{$display_column} ?? (string) $id;
    }
}

<?php

namespace App\Domain\Signals\CustomFields;

use App\Domain\Signals\BaseSignal;
use App\Domain\Signals\ChangeSentence;
use Carbon\Exceptions\InvalidFormatException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Extra fields on a record got other values. They live in a pivot table, which
 * tells nobody it changed, so the record says it here: one row per field, named
 * the way the administrator named the field.
 */
class CustomFieldValuesChanged extends BaseSignal
{
    /**
     * @param  array<int, array{name: string, field_type: string}>  $fields  By field id.
     * @param  array<int, ?string>  $before  Stored value per field id before the save.
     * @param  array<int, ?string>  $after  Stored value per field id after the save.
     */
    public function __construct(
        public Model $record,
        public array $fields,
        public array $before,
        public array $after,
    ) {
        parent::__construct();
    }

    public static function key(): string
    {
        return 'customfields.values_changed';
    }

    public static function label(): string
    {
        return 'Extra velden gewijzigd';
    }

    public function subject(): Model
    {
        return $this->record;
    }

    public function activityCategory(): string
    {
        return 'updated';
    }

    public function activityDescription(): ?string
    {
        $changes = $this->changes();

        return $changes === [] ? null : ChangeSentence::of($changes);
    }

    public function changes(): array
    {
        $changes = [];

        foreach ($this->fields as $field_id => $field) {
            $old = $this->before[$field_id] ?? null;
            $new = $this->after[$field_id] ?? null;

            if (($this->blank($old) && $this->blank($new)) || (string) $old === (string) $new) {
                continue;
            }

            $changes[] = [
                'field' => 'custom_field_' . $field_id,
                'label' => $field['name'],
                'old_value' => $this->blank($old) ? null : (string) $old,
                'new_value' => $this->blank($new) ? null : (string) $new,
                'old_label' => $this->display($field['field_type'], $old),
                'new_label' => $this->display($field['field_type'], $new),
            ];
        }

        return $changes;
    }

    private function display(string $field_type, ?string $value): ?string
    {
        if ($this->blank($value)) {
            return null;
        }

        if ($field_type === 'boolean') {
            return in_array(strtolower($value), ['1', 'true', 'ja', 'yes'], true) ? 'Ja' : 'Nee';
        }

        if ($field_type === 'date') {
            return $this->date($value);
        }

        return $value;
    }

    /** A date field holds a day, not a moment: shown as it is read, without a time zone. */
    private function date(string $value): string
    {
        try {
            return Carbon::parse($value)->format('d-m-Y');
        } catch (InvalidFormatException) {
            return $value;
        }
    }

    private function blank(?string $value): bool
    {
        return $value === null || $value === '';
    }
}

<?php

namespace App\Domain\Signals;

use Illuminate\Database\Eloquent\Model;

/**
 * The generic signal every model emits when it is created, updated or deleted.
 * Carries one entry per changed field, each with its readable label and its
 * value before and after, so the log never has to parse a sentence to know what
 * actually happened.
 */
class ModelChanged extends BaseSignal
{
    /**
     * @param  array<int, array{field: string, label: string, old: ?string, new: ?string}>  $changes
     */
    public function __construct(
        public Model $model,
        public string $action,
        public array $changes = [],
        public ?string $required_permission = null,
    ) {
        parent::__construct();
    }

    public function requiredPermission(): ?string
    {
        return $this->required_permission;
    }

    public static function key(): string
    {
        return 'model.changed';
    }

    public function eventKey(): string
    {
        return mb_strtolower(class_basename($this->model)) . '.' . $this->action;
    }

    public static function label(): string
    {
        return 'Record gewijzigd';
    }

    public function subject(): Model
    {
        return $this->model;
    }

    public function activityDescription(): ?string
    {
        return match ($this->action) {
            'created' => 'Aangemaakt',
            'deleted' => 'Verwijderd',
            'restored' => 'Hersteld',
            default => $this->changeSentence(),
        };
    }

    public function activityCategory(): string
    {
        return match ($this->action) {
            'created' => 'created',
            'deleted', 'restored' => 'status',
            default => 'update',
        };
    }

    /** @return array<int, array<string, mixed>> */
    public function changes(): array
    {
        return $this->changes;
    }

    /**
     * Repeat updates of one record inside a request are one action to the person
     * who triggered them. Creation and deletion happen once and never merge.
     */
    public function mergeKey(): ?string
    {
        if ($this->action !== 'updated') {
            return null;
        }

        /**
         * The permission is part of the key so an open entry and a gated one are
         * never folded together. Without it the sensitive values would land in
         * the entry everyone can read.
         */
        return 'updated|' . ($this->required_permission ?? '');
    }

    private function changeSentence(): ?string
    {
        if ($this->changes === []) {
            return null;
        }

        $parts = array_map(
            fn (array $change) => $change['label'] . ' gewijzigd van \''
                . ($change['old_label'] ?? '-') . '\' naar \'' . ($change['new_label'] ?? '-') . '\'',
            $this->changes,
        );

        return implode(', ', $parts);
    }
}

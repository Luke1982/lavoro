<?php

namespace App\Domain\Integrations\Data;

/**
 * A record in the shape every connector speaks, whatever system it came from.
 * A connector fills in only what its system has and says which fields that are,
 * so a field it never carries is neither overwritten nor compared.
 */
abstract class RecordData
{
    /** Every kind of record has one: lists here show it by its name. */
    public ?string $name = null;

    /** Lavoro's own id, for a system that wants a code of its own for a new record. Never compared. */
    public ?string $reference = null;

    /** @return array<int, string> */
    abstract public static function fields(): array;

    /** @param  array<string, mixed>  $values */
    public static function from(array $values): static
    {
        $data = new static;

        foreach (static::fields() as $field) {
            $data->{$field} = $data->clean($field, $values[$field] ?? null);
        }

        $data->reference = isset($values['reference']) ? (string) $values['reference'] : null;

        return $data;
    }

    /**
     * @param  array<int, string>  $fields
     * @return array<string, mixed>
     */
    public function only(array $fields): array
    {
        $values = [];

        foreach (array_intersect(static::fields(), $fields) as $field) {
            $values[$field] = $this->{$field};
        }

        return $values;
    }

    /**
     * A short fingerprint per field, so a sync can tell per field "changed
     * since last time" from "the same as last time" without keeping a copy of
     * anyone's data -- an IBAN is not stored twice, only its hash.
     *
     * @param  array<int, string>  $fields
     * @return array<string, string>
     */
    public function fingerprints(array $fields): array
    {
        $fingerprints = [];

        foreach ($this->only($fields) as $field => $value) {
            $fingerprints[$field] = substr(hash('sha256', (string) $this->comparable($value)), 0, 16);
        }

        ksort($fingerprints);

        return $fingerprints;
    }

    protected function clean(string $field, mixed $value): mixed
    {
        if (!is_string($value) && !is_numeric($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * Case and spacing are not a change: SnelStart writes a postcode its own
     * way, and that alone must not make every customer look edited.
     */
    protected function comparable(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_float($value) || is_int($value)) {
            return number_format((float) $value, 4, '.', '');
        }

        return mb_strtolower(preg_replace('/\s+/', '', (string) $value));
    }
}

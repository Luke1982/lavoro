<?php

namespace App\Domain\Signals\Users;

use Illuminate\Support\Arr;

/**
 * A list of named things before and after one action: the roles someone has,
 * the people on an appointment, the moments a standard e-mail goes out on.
 *
 * Keyed by id and carrying the name as it was at that moment, so a role that is
 * renamed or a person who leaves later does not rewrite what the trail says.
 */
final class NameListChange
{
    /**
     * @param  array<int|string, string>  $before  id => name
     * @param  array<int|string, string>  $after  id => name
     */
    public function __construct(
        public array $before,
        public array $after,
    ) {}

    /** @return array<int, string> */
    public function added(): array
    {
        return array_values(array_diff_key($this->after, $this->before));
    }

    /** @return array<int, string> */
    public function removed(): array
    {
        return array_values(array_diff_key($this->before, $this->after));
    }

    /** @return array<int, int|string> */
    public function addedIds(): array
    {
        return array_keys(array_diff_key($this->after, $this->before));
    }

    /** @return array<int, int|string> */
    public function removedIds(): array
    {
        return array_keys(array_diff_key($this->before, $this->after));
    }

    public function isEmpty(): bool
    {
        return $this->added() === [] && $this->removed() === [];
    }

    /**
     * "Jan en Piet toegevoegd, Klaas verwijderd", with the verbs of the caller:
     * a person is taken off an appointment, a role is taken away.
     */
    public function sentence(string $added_verb = 'toegevoegd', string $removed_verb = 'verwijderd'): string
    {
        $parts = [];

        if ($this->added() !== []) {
            $parts[] = self::names($this->added()) . ' ' . $added_verb;
        }

        if ($this->removed() !== []) {
            $parts[] = self::names($this->removed()) . ' ' . $removed_verb;
        }

        return implode(', ', $parts);
    }

    /**
     * One change row for the whole list: the ids as the raw value, the names as
     * what a person reads.
     *
     * @return array<string, ?string>
     */
    public function change(string $field, string $label): array
    {
        return [
            'field' => $field,
            'label' => $label,
            'old_value' => self::ids($this->before),
            'new_value' => self::ids($this->after),
            'old_label' => $this->before === [] ? null : self::names(array_values($this->before)),
            'new_label' => $this->after === [] ? null : self::names(array_values($this->after)),
        ];
    }

    /**
     * What came and what went as two rows, for a list too long to read twice
     * over: a role holds dozens of permissions and one was added.
     *
     * @return array<int, array<string, ?string>>
     */
    public function deltaChanges(string $field, string $added_label, string $removed_label): array
    {
        $rows = [];

        if ($this->added() !== []) {
            $rows[] = [
                'field' => $field . '_added',
                'label' => $added_label,
                'old_value' => null,
                'new_value' => implode(',', $this->addedIds()),
                'old_label' => null,
                'new_label' => self::names($this->added()),
            ];
        }

        if ($this->removed() !== []) {
            $rows[] = [
                'field' => $field . '_removed',
                'label' => $removed_label,
                'old_value' => implode(',', $this->removedIds()),
                'new_value' => null,
                'old_label' => self::names($this->removed()),
                'new_label' => null,
            ];
        }

        return $rows;
    }

    /** @param array<int, string> $names */
    public static function names(array $names): string
    {
        return Arr::join($names, ', ', ' en ');
    }

    /** @param array<int|string, string> $list */
    private static function ids(array $list): ?string
    {
        if ($list === []) {
            return null;
        }

        $ids = array_keys($list);
        sort($ids);

        return implode(',', $ids);
    }
}

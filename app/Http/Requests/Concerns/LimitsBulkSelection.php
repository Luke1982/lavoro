<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A bulk action on records picked in a list. Every record it changes is
 * announced, and each announcement can set off more work: a file to delete, a
 * date to move, a line on a timeline. Past a few hundred records that no longer
 * fits in one request, so a larger selection is refused up front with a message
 * saying how many fit, before anything is changed.
 *
 * @mixin FormRequest
 */
trait LimitsBulkSelection
{
    public const BULK_LIMIT = 500;

    /** @return array<int, string> */
    protected function bulkSelectionRules(): array
    {
        return ['required', 'array', 'min:1', 'max:' . self::BULK_LIMIT];
    }

    /**
     * "Je hebt 812 storingen geselecteerd, je kunt er maximaal 500 tegelijk bijwerken."
     *
     * @param  string  $field  The input holding the selected ids.
     * @param  string  $records  What was picked, in the plural: "storingen".
     * @param  string  $action  What happens to them: "bijwerken".
     * @return array<string, string>
     */
    protected function bulkSelectionMessages(string $field, string $records, string $action): array
    {
        return [
            $field . '.max' => 'Je hebt ' . count((array) $this->input($field)) . ' ' . $records
                . ' geselecteerd, je kunt er maximaal ' . self::BULK_LIMIT . ' tegelijk ' . $action
                . '. Doe het in delen.',
        ];
    }
}

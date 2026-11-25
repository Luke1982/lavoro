<?php

namespace App\Http\Requests\Concerns;

use App\Support\BulkSelection;
use Illuminate\Foundation\Http\FormRequest;

/**
 * A bulk action on records picked in a list. A selection larger than
 * BulkSelection::LIMIT is refused up front with a message saying how many fit,
 * before anything is changed.
 *
 * @mixin FormRequest
 */
trait LimitsBulkSelection
{
    /** @return array<int, string> */
    protected function bulkSelectionRules(): array
    {
        return ['required', 'array', 'min:1', 'max:' . BulkSelection::LIMIT];
    }

    /**
     * The sentence BulkSelection::tooMany() writes, for the field holding the ids.
     *
     * @param  string  $field  The input holding the selected ids.
     * @param  string  $records  What was picked, in the plural: "storingen".
     * @param  string  $action  What happens to them: "bijwerken".
     * @return array<string, string>
     */
    protected function bulkSelectionMessages(string $field, string $records, string $action): array
    {
        return [
            $field . '.max' => BulkSelection::tooMany(count((array) $this->input($field)), $records, $action),
        ];
    }
}

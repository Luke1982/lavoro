<?php

namespace App\Http\Requests\Concerns;

use App\Models\Document;
use Illuminate\Support\Collection;

/**
 * A bulk action on documents does the ones this person may, and leaves the
 * others: one document on a record they cannot open does not stop the rest.
 *
 * @property int[] $ids
 */
trait SplitsDocumentsByRight
{
    /** @return array{0: Collection<int, Document>, 1: Collection<int, Document>} */
    protected function documentsSplitBy(string $ability): array
    {
        return Document::whereIn('id', $this->ids)
            ->get()
            ->partition(fn (Document $document) => $this->user()->can($ability, $document))
            ->all();
    }
}

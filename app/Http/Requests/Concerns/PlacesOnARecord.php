<?php

namespace App\Http\Requests\Concerns;

use App\Models\Document;
use App\Models\Image;
use App\Models\Remark;
use Illuminate\Foundation\Http\FormRequest;

/**
 * For a request that puts a photo, a document or a remark on the record it
 * names by class and id. Whoever may see that record may add to it, as its own
 * screen decides; an appointment is seen by whoever may give feedback on it, and
 * a keurpunt only takes one from whoever may fill in its keuring (mayPlaceOn).
 *
 * A type that is not on the list, or a record that does not exist, is let
 * through, so rules() or the controller can refuse it with a message instead of
 * a bare 403.
 *
 * @mixin FormRequest
 */
trait PlacesOnARecord
{
    /** @param  class-string<Image|Document|Remark>  $attachment  What is being added. */
    protected function maySeeTheRecord(string $attachment): bool
    {
        $morph = $attachment::attachmentMorph();
        $record_class = $attachment::attachableType($this->input($morph . '_type'));
        $record = $record_class === null ? null : $record_class::find($this->integer($morph . '_id'));

        return $record === null || $attachment::mayPlaceOn($this->user(), $record);
    }
}

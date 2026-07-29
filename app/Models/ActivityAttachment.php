<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row of the activityables pivot, as a model in its own right.
 *
 * Activity already has a relation per kind of record it can hang off, which is
 * what a page needs. Reading history the other way round — here is an entry,
 * what is it about — wants the links themselves without caring which sort they
 * are, and eager loading them keeps a list of fifty entries to one extra query.
 *
 * @property int $activity_id
 * @property string $activityable_type
 * @property int $activityable_id
 */
class ActivityAttachment extends Model
{
    protected $table = 'activityables';

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}

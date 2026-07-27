<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One field that changed, with the value before and after. Kept as rows rather
 * than a blob so a trace can be queried directly: every time this order's stage
 * moved, every price that ever dropped, every reassignment in a date range.
 */
class ActivityChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'activity_id',
        'field',
        'label',
        'old_value',
        'new_value',
        'old_label',
        'new_label',
    ];

    public function activity(): BelongsTo
    {
        return $this->belongsTo(Activity::class);
    }
}

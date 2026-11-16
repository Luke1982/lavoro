<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * One thing a person has set aside on their bakje.
 *
 * @property int $id
 * @property int $user_id
 * @property string $trayable_type
 * @property int $trayable_id
 * @property bool $pinned
 * @property int $position
 */
class TrayItem extends Model
{
    protected $fillable = [
        'user_id',
        'trayable_type',
        'trayable_id',
        'pinned',
        'position',
    ];

    protected $casts = [
        'pinned' => 'boolean',
        'position' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function trayable(): MorphTo
    {
        return $this->morphTo();
    }
}

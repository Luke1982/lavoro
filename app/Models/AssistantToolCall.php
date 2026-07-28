<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One attempt by the assistant to use a capability, successful or not.
 *
 * @mixin Model
 */
class AssistantToolCall extends Model
{
    protected $fillable = [
        'user_id',
        'tool',
        'external_id',
        'arguments',
        'outcome',
        'result',
        'duration_ms',
    ];

    protected $casts = [
        'arguments' => 'array',
        'duration_ms' => 'integer',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

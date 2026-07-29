<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question and what came back.
 *
 * @property string $question
 * @property string|null $answer
 */
class AssistantQuestion extends Model
{
    protected $fillable = [
        'user_id',
        'question',
        'answer',
        'failure',
        'page',
        'tools',
        'rounds',
        'cost_micros',
    ];

    protected $casts = [
        'tools' => 'array',
    ];

    /**
     * The path is only kept so an old answer can be read back in context, and the
     * column holds 255. Cut here rather than at the caller: a write that fails
     * loses the whole question over a detail nothing depends on.
     */
    public function setPageAttribute(?string $value): void
    {
        $this->attributes['page'] = $value === null ? null : mb_substr($value, 0, 255);
    }

    public function setFailureAttribute(?string $value): void
    {
        $this->attributes['failure'] = $value === null ? null : mb_substr($value, 0, 255);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

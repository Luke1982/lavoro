<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A question worth asking again.
 *
 * @property ?int $user_id
 * @property string $label
 * @property string $question
 * @property ?string $context
 */
class AssistantPrompt extends Model
{
    protected $fillable = ['user_id', 'label', 'question', 'context', 'sort'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The ones this person may see: everything shipped, plus their own.
     *
     * Somebody else's private question is theirs; there is no sharing here yet
     * and pretending otherwise would leak how another team works.
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('user_id')->orWhere('user_id', $user->id));
    }

    /**
     * The ones that belong on this page.
     *
     * Matched on the tail of the pattern so "serviceorders.show" fits every
     * werkbon, and a null context means the question fits anywhere.
     */
    public function scopeForContext(Builder $query, ?string $context): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('context')->when(
            filled($context),
            fn (Builder $inner) => $inner->orWhere('context', $context),
        ));
    }
}

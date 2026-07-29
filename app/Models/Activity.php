<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class Activity extends Model
{
    use HasFactory;

    protected $fillable = [
        'category',
        'description',
        'event_key',
        'subject_type',
        'subject_id',
        'user_id',
        'actor_type',
        'actor_name',
        'metadata',
        'occurred_at',
        'correlation_id',
        'required_permission',
    ];

    protected $casts = [
        'metadata' => 'array',
        'occurred_at' => 'datetime',
    ];

    /**
     * Entries whose subject holds sensitive values are gated. Anything without a
     * required permission is open, so the scope never hides ordinary history.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if ($user?->isAdmin()) {
            return $query;
        }

        $held = $user ? $user->permissionNames() : [];

        return $query->where(function (Builder $q) use ($held) {
            $q->whereNull('required_permission');

            if ($held !== []) {
                $q->orWhereIn('required_permission', $held);
            }
        });
    }

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Every record this entry hangs off, whatever kind they are.
     *
     * The named relations below answer "show me this werkbon's history"; this
     * one answers the reverse, which is what searching across records needs.
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(ActivityAttachment::class);
    }

    public function fieldChanges(): HasMany
    {
        return $this->hasMany(ActivityChange::class);
    }

    public function serviceOrders(): MorphToMany
    {
        return $this->morphedByMany(ServiceOrder::class, 'activityable')->withTimestamps();
    }

    public function serviceOrderStages(): MorphToMany
    {
        return $this->morphedByMany(ServiceOrderStage::class, 'activityable')->withTimestamps();
    }

    public function tickets(): MorphToMany
    {
        return $this->morphedByMany(Ticket::class, 'activityable')->withTimestamps();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}

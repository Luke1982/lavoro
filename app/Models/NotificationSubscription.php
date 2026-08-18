<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * A standing wish to be told about one kind of fact. Carries no rights of its
 * own: whether it delivers is decided against the subscriber's permissions every
 * time the fact occurs.
 *
 * Er zijn twee vormen, en ze worden verschillend afgerekend. Zonder record gaat
 * het over een soort nieuws, waar dan ook vandaan, en daarvoor moet je alles van
 * dat soort mogen lezen. Mét record gaat het over dat ene ding, en dan telt of je
 * dat ding mag zien — anders zou wie een werkbon uitvoert de storingen erop niet
 * kunnen volgen zonder het brede leesrecht dat hij nu juist niet heeft.
 */
class NotificationSubscription extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'subscribable_type',
        'subscribable_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function subscribable(): MorphTo
    {
        return $this->morphTo();
    }

    /** Abonnementen op dit ene record, ongeacht op welk soort nieuws. */
    public function scopeForRecord(Builder $query, Model $record): Builder
    {
        return $query
            ->where('subscribable_type', $record->getMorphClass())
            ->where('subscribable_id', $record->getKey());
    }

    /** Abonnementen op een soort nieuws in het algemeen, zonder record eronder. */
    public function scopeForType(Builder $query, string $type): Builder
    {
        return $query->where('type', $type)->whereNull('subscribable_type');
    }
}

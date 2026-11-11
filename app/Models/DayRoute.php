<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A calculated route, put in one person's day. Only what they need on the road
 * is kept: the stops in order with the arrival at each, the time back, and the
 * Google Maps links that drive it. Where the route starts is in the links.
 *
 * One per person per day; another route in the same day replaces it.
 *
 * @property int $user_id
 * @property CarbonImmutable $date
 * @property list<array{name: string, arrival: string}> $stops
 * @property string $finish
 * @property list<string> $links
 * @property array<int|string, string>|null $google_items Google's event id per synced calendar id
 */
class DayRoute extends Model
{
    /** Google Maps takes ten addresses per link: the most stops, with the start twice, make five. */
    public const MAX_LINKS = 5;

    protected $fillable = ['user_id', 'date', 'stops', 'finish', 'links'];

    protected function casts(): array
    {
        return [
            'date' => 'immutable_date',
            'stops' => 'array',
            'links' => 'array',
            'google_items' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** Everyone's routes for whoever sees the whole planning, otherwise only their own. */
    public function scopeVisibleTo(Builder $query, User $viewer): Builder
    {
        return $viewer->can('seeEveryone', self::class) ? $query : $query->where('user_id', $viewer->id);
    }

    /** "7 adressen, terug om 16:45" */
    public function summary(): string
    {
        $count = count($this->stops ?? []);

        return $count . ' ' . ($count === 1 ? 'adres' : 'adressen') . ', terug om ' . $this->finish;
    }

    /** "dinsdag 12 november" */
    public function dayLabel(): string
    {
        return $this->date?->locale('nl')->isoFormat('dddd D MMMM') ?? '';
    }
}

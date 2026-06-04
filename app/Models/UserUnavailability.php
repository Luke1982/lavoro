<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserUnavailability extends Model
{
    protected $fillable = [
        'user_id',
        'type',
        'label',
        'day_of_week',
        'start_time',
        'end_time',
        'repeat',
        'reference_date',
        'date',
        'end_date',
    ];

    protected function casts(): array
    {
        return [
            'date'           => 'date',
            'end_date'       => 'date',
            'reference_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function scopeForWeek(Builder $query, Carbon $weekStart, Carbon $weekEnd): Builder
    {
        return $query->where(function ($q) use ($weekStart, $weekEnd) {
            // Holiday overlaps the week when it starts before weekEnd AND ends after weekStart.
            $q->where('type', 'holiday')
              ->where('date', '<=', $weekEnd->toDateString())
              ->where(function ($q2) use ($weekStart) {
                  $q2->whereNull('end_date')
                     ->orWhere('end_date', '>=', $weekStart->toDateString());
              });
        })->orWhere('type', 'recurring');
    }

    public function expandForWeek(Carbon $weekStart, Carbon $weekEnd): array
    {
        if ($this->type === 'holiday') {
            $end     = $this->end_date ?? $this->date;
            $current = $this->date->copy()->max($weekStart);
            $blocks  = [];

            while ($current->lte($end) && $current->lte($weekEnd)) {
                $blocks[] = [
                    'user_id'    => $this->user_id,
                    'date'       => $current->toDateString(),
                    'start_time' => null,
                    'end_time'   => null,
                    'label'      => $this->label,
                ];
                $current->addDay();
            }

            return $blocks;
        }

        $blocks  = [];
        $current = $weekStart->copy()->startOfDay();

        while ($current->lte($weekEnd)) {
            // Carbon dayOfWeekIso: 1=Mon..7=Sun → convert to 0=Mon..6=Sun
            $dow = $current->dayOfWeekIso - 1;

            if ($dow === (int) $this->day_of_week) {
                $include = false;

                if ($this->repeat === 'weekly') {
                    $include = true;
                } elseif ($this->repeat === 'biweekly' && $this->reference_date !== null) {
                    // Week 0 (the reference week itself) is the first occurrence.
                    $weeksDiff = (int) $this->reference_date->diffInWeeks($current);
                    $include   = $weeksDiff % 2 === 0;
                }

                if ($include) {
                    $blocks[] = [
                        'user_id'    => $this->user_id,
                        'date'       => $current->toDateString(),
                        'start_time' => $this->start_time,
                        'end_time'   => $this->end_time,
                        'label'      => $this->label,
                    ];
                }
            }

            $current->addDay();
        }

        return $blocks;
    }
}

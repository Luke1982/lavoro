<?php

namespace App\Models;

use App\Models\Traits\RecordsHistory;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A stretch of time someone cannot be planned: a holiday, or a fixed part of
 * the week. Has no timeline of its own; its history is on the person.
 */
class UserUnavailability extends Model
{
    use RecordsHistory;

    private const WEEKDAYS = ['maandag', 'dinsdag', 'woensdag', 'donderdag', 'vrijdag', 'zaterdag', 'zondag'];

    protected array $activity_labels = [
        'user_id' => 'Medewerker',
        'type' => 'Soort',
        'label' => 'Omschrijving',
        'day_of_week' => 'Dag',
        'start_time' => 'Van',
        'end_time' => 'Tot',
        'repeat' => 'Herhaling',
        'reference_date' => 'Eerste week',
        'date' => 'Datum',
        'end_date' => 'Tot en met',
    ];

    protected array $activity_relations = [
        'user_id' => ['user', 'name'],
    ];

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
            'date' => 'date',
            'end_date' => 'date',
            'reference_date' => 'date',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * What the two screens that show them show: a colleague's page has anyone's
     * for whoever may read colleagues, the planner those of the people it plans
     * for whoever may read appointments. The user query leaves out MajorLabel's
     * own accounts and colleagues who are gone, as both screens do.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return match (true) {
            $user?->can('viewAny', User::class) === true => $query->whereHas('user'),
            $user?->can('viewAny', Event::class) === true => $query->whereHas('user', fn ($users) => $users->where('plannable', true)),
            default => $query->whereRaw('1 = 0'),
        };
    }

    /** @return array<int, User|null> */
    public function historyTimelines(string $action): array
    {
        return [$this->user()->withTrashed()->first()];
    }

    /** "Afwezig (Vakantie): 01-12-2026 t/m 05-12-2026" or "Afwezig: elke maandag 08:00-12:00". */
    public function historyLabel(): ?string
    {
        $what = $this->label ? ' (' . $this->label . ')' : '';

        if ($this->type === 'holiday') {
            $until = $this->end_date && !$this->end_date->equalTo($this->date)
                ? ' t/m ' . $this->end_date->format('d-m-Y')
                : '';

            return 'Afwezig' . $what . ': ' . $this->date?->format('d-m-Y') . $until;
        }

        $every = $this->repeat === 'biweekly' ? 'om de week op ' : 'elke ';
        $times = $this->start_time
            ? ' ' . substr((string) $this->start_time, 0, 5) . '-' . substr((string) $this->end_time, 0, 5)
            : '';

        return 'Afwezig' . $what . ': ' . $every . (self::WEEKDAYS[(int) $this->day_of_week] ?? '') . $times;
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
            $end = $this->end_date ?? $this->date;
            $current = $this->date->copy()->max($weekStart);
            $blocks = [];

            while ($current->lte($end) && $current->lte($weekEnd)) {
                $blocks[] = [
                    'user_id' => $this->user_id,
                    'date' => $current->toDateString(),
                    'start_time' => null,
                    'end_time' => null,
                    'label' => $this->label,
                ];
                $current->addDay();
            }

            return $blocks;
        }

        $blocks = [];
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
                    $include = $weeksDiff % 2 === 0;
                }

                if ($include) {
                    $blocks[] = [
                        'user_id' => $this->user_id,
                        'date' => $current->toDateString(),
                        'start_time' => $this->start_time,
                        'end_time' => $this->end_time,
                        'label' => $this->label,
                    ];
                }
            }

            $current->addDay();
        }

        return $blocks;
    }
}

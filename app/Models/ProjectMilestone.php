<?php

namespace App\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A step a project has to pass. Its history is written by the milestone signals
 * onto the project, which is where people read it; the milestone has no page.
 */
class ProjectMilestone extends Model
{
    public const REACHED = 'reached';

    public const REACHED_LATE = 'reached_late';

    public const OVERDUE = 'overdue';

    public const OPEN = 'open';

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'projected_date',
        'actual_date',
        'assigned_user_id',
        'overdue_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'overdue_notified_at' => 'datetime',
        ];
    }

    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function assignedUser()
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    /** The werkbonnen that complete this step once they are all closed. */
    public function serviceOrders(): HasMany
    {
        return $this->hasMany(ServiceOrder::class, 'project_milestone_id');
    }

    public function isReached(): bool
    {
        return filled($this->actual_date);
    }

    /**
     * Where this step stands on the given day, and by how many days it is or was
     * late. One reading for the project page, the export and the reminders, so a
     * milestone is never late on one screen and on time on the next.
     *
     * @return array{state: string, days_late: ?int}
     */
    public function lateness(CarbonInterface $today): array
    {
        $planned = $this->projected_date ? Carbon::parse($this->projected_date)->startOfDay() : null;
        $reached = $this->actual_date ? Carbon::parse($this->actual_date)->startOfDay() : null;
        $today = Carbon::parse($today->toDateString());

        $days_late = match (true) {
            $planned === null => null,
            $reached !== null => max(0, (int) $planned->diffInDays($reached, false)),
            $planned->lt($today) => (int) $planned->diffInDays($today, false),
            default => null,
        };

        return [
            'state' => match (true) {
                $reached !== null => $days_late ? self::REACHED_LATE : self::REACHED,
                $days_late !== null => self::OVERDUE,
                default => self::OPEN,
            },
            'days_late' => $days_late,
        ];
    }
}

<?php

namespace App\Support\Spreadsheets;

use App\Enums\EventCompletionStatus;
use App\Models\Event;
use App\Models\EventUserExecution;
use App\Models\User;
use Illuminate\Support\Carbon;

/**
 * What one monteur was planned for on one appointment and what they registered.
 * The planner export and the project export print the same columns from it, so a
 * monteur's hours read the same in both files.
 */
final class AppointmentHours
{
    public const COLUMNS = [
        'Geplande start' => SheetWriter::DATETIME,
        'Geplande eind' => SheetWriter::DATETIME,
        'Geplande uren (excl. pauze)' => null,
        'Werkelijke start' => SheetWriter::DATETIME,
        'Werkelijke eind' => SheetWriter::DATETIME,
        'Werkelijke uren (excl. pauze)' => null,
        'Reistijd (uren)' => null,
        'Geplande pauze (uren)' => null,
        'Werkelijke pauze (uren)' => null,
        'Status' => null,
    ];

    private function __construct(
        public readonly Carbon $planned_start,
        public readonly Carbon $planned_end,
        public readonly float $planned_hours,
        public readonly ?Carbon $actual_start,
        public readonly ?Carbon $actual_end,
        public readonly ?float $worked_hours,
        public readonly float $travel_hours,
        public readonly float $planned_break_hours,
        public readonly ?float $actual_break_hours,
        public readonly string $status,
    ) {}

    /**
     * @param  User  $member  an executing user of the appointment, loaded with its pivot
     */
    public static function for(Event $event, User $member, string $timezone): self
    {
        $execution = $event->executions->firstWhere('user_id', $member->id) ?? new EventUserExecution;
        $planned_break_minutes = (int) ($member->pivot->breaktime ?? 0);

        [$planned_start, $planned_end] = $event->plannedTimesFor($member, $timezone);

        return new self(
            planned_start: $planned_start,
            planned_end: $planned_end,
            planned_hours: round($planned_start->floatDiffInHours($planned_end) - $planned_break_minutes / 60, 2),
            actual_start: $execution->actual_start,
            actual_end: $execution->actual_end,
            worked_hours: $execution->hoursWorked($planned_break_minutes),
            travel_hours: self::hours((int) $execution->travel_time_minutes),
            planned_break_hours: self::hours($planned_break_minutes),
            actual_break_hours: $execution->actual_breaktime_minutes === null
                ? null
                : self::hours($execution->actual_breaktime_minutes),
            status: $execution->completion_status ?? EventCompletionStatus::planned->value,
        );
    }

    /** The monteur filled in when they started and when they finished. */
    public function isRegistered(): bool
    {
        return $this->worked_hours !== null;
    }

    /** The break that counts: the one registered, or the planned one when none was. */
    public function breakHours(): float
    {
        return $this->actual_break_hours ?? $this->planned_break_hours;
    }

    /**
     * @return array<string, mixed> values by the headers of COLUMNS
     */
    public function row(): array
    {
        return array_combine(array_keys(self::COLUMNS), [
            $this->planned_start,
            $this->planned_end,
            $this->planned_hours,
            $this->actual_start,
            $this->actual_end,
            $this->worked_hours,
            $this->travel_hours,
            $this->planned_break_hours,
            $this->actual_break_hours,
            $this->status,
        ]);
    }

    private static function hours(int $minutes): float
    {
        return round($minutes / 60, 2);
    }
}

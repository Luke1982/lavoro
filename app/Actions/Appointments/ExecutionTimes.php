<?php

namespace App\Actions\Appointments;

use Carbon\CarbonImmutable;

/**
 * One technician's own times on an appointment: when they started and stopped,
 * how long they travelled and how long their break was.
 *
 * The moments are stored UTC strings, Y-m-d H:i:s, as the columns hold them. A
 * break left null means none was said, which the hours worked read as the
 * planned break rather than as no break.
 */
final class ExecutionTimes
{
    public function __construct(
        public readonly string $actual_start,
        public readonly string $actual_end,
        public readonly int $travel_time_minutes = 0,
        public readonly ?int $actual_breaktime_minutes = null,
    ) {}

    /**
     * From what the times window sent and its request validated: any date the
     * validator took, turned into the stored form.
     *
     * @param  array<string, mixed>  $validated
     */
    public static function fromValidated(array $validated): self
    {
        $breaktime = $validated['actual_breaktime_minutes'] ?? null;

        return new self(
            actual_start: CarbonImmutable::parse($validated['actual_start'])->utc()->format('Y-m-d H:i:s'),
            actual_end: CarbonImmutable::parse($validated['actual_end'])->utc()->format('Y-m-d H:i:s'),
            travel_time_minutes: (int) ($validated['travel_time_minutes'] ?? 0),
            actual_breaktime_minutes: $breaktime === null ? null : (int) $breaktime,
        );
    }

    /**
     * The times as the times window sends them, to be checked by its own request.
     *
     * @return array{actual_start: string, actual_end: string, travel_time_minutes: int, actual_breaktime_minutes: ?int}
     */
    public function toArray(): array
    {
        return [
            'actual_start' => $this->actual_start,
            'actual_end' => $this->actual_end,
            'travel_time_minutes' => $this->travel_time_minutes,
            'actual_breaktime_minutes' => $this->actual_breaktime_minutes,
        ];
    }
}

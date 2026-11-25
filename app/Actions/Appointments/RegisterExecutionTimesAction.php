<?php

namespace App\Actions\Appointments;

use App\Domain\Signals\Appointments\ExecutionTimesRegistered;
use App\Domain\Signals\Signals;
use App\Enums\EventCompletionStatus;
use App\Exceptions\Refusal;
use App\Models\Event;
use App\Models\EventUserExecution;
use App\Models\User;

/**
 * Writes one technician's own times on an appointment: start, end, travel and
 * break, and announces it so the werkbon's timeline says whose times they were.
 *
 * Finishing the part is asked for in so many words. The times window on the
 * planner finishes it, with the signature it insists on; the assistant can be
 * told the hours but cannot sign, so it keeps the times and leaves the part
 * where it was, and the window then opens on them with only the signature left
 * to give. Finishing without a signature is refused here rather than trusted
 * to every caller, because a part marked finished without one would be a lie
 * about it.
 */
class RegisterExecutionTimesAction
{
    public function execute(
        Event $event,
        User $executor,
        ExecutionTimes $times,
        bool $complete,
        ?string $signature_base64 = null,
    ): EventUserExecution {
        if ($complete && blank($signature_base64)) {
            throw new Refusal('Afronden kan alleen met een handtekening.');
        }

        $execution = $event->executionFor($executor->id);
        $before = $execution->snapshot();

        $execution->actual_start = $times->actual_start;
        $execution->actual_end = $times->actual_end;
        $execution->travel_time_minutes = $times->travel_time_minutes;
        $execution->actual_breaktime_minutes = $times->actual_breaktime_minutes;

        if ($complete) {
            $execution->signature_base64 = $signature_base64;
            $execution->completion_status = EventCompletionStatus::completed->value;
        }

        $execution->save();

        Signals::dispatch(new ExecutionTimesRegistered($event, $executor, $before, $execution->snapshot()));

        return $execution;
    }
}

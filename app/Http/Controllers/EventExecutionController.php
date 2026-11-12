<?php

namespace App\Http\Controllers;

use App\Domain\Signals\Appointments\ExecutionCancelled;
use App\Domain\Signals\Appointments\ExecutionStarted;
use App\Domain\Signals\Appointments\ExecutionTimesRegistered;
use App\Domain\Signals\Appointments\ExecutionTimesReleased;
use App\Domain\Signals\Signals;
use App\Enums\EventCompletionStatus;
use App\Exceptions\Refusal;
use App\Http\Requests\EventExecutionShowOthersRequest;
use App\Http\Requests\EventExecutionTransitionRequest;
use App\Http\Requests\EventExecutionUpdateOthersRequest;
use App\Http\Requests\EventExecutionUpdateRequest;
use App\Http\Requests\EventReleaseTimesRequest;
use App\Models\Event;
use App\Models\EventUserExecution;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class EventExecutionController extends Controller
{
    private const REPORTED_FIELDS = [
        'completion_status',
        'actual_start',
        'actual_end',
        'travel_time_minutes',
        'actual_breaktime_minutes',
    ];

    public function show(Event $event)
    {
        return response()->json($this->details($event->executionFor(Auth::id())));
    }

    public function transition(EventExecutionTransitionRequest $request, Event $event)
    {
        $status = $request->validated('status');
        $execution = $event->executionFor(Auth::id());
        $before = $execution->snapshot();

        if ($status === EventCompletionStatus::ongoing->value) {
            $execution->actual_start = now();
        }

        if ($status === EventCompletionStatus::completed->value) {
            $execution->actual_end = now();
            $execution->signature_base64 = $request->validated('signature_base64');
        }

        $execution->completion_status = $status;
        $execution->save();

        $signal_class = match ($status) {
            EventCompletionStatus::ongoing->value => ExecutionStarted::class,
            EventCompletionStatus::completed->value => ExecutionTimesRegistered::class,
            EventCompletionStatus::cancelled->value => ExecutionCancelled::class,
        };

        Signals::dispatch(new $signal_class($event, $request->user(), $before, $execution->snapshot()));

        return response()->json($this->payload($execution));
    }

    public function update(EventExecutionUpdateRequest $request, Event $event)
    {
        return response()->json($this->register($request, $event, $request->user()));
    }

    public function showFor(EventExecutionShowOthersRequest $request, Event $event, User $target_user)
    {
        return response()->json($this->details($event->executionFor($target_user->id)));
    }

    public function updateFor(EventExecutionUpdateOthersRequest $request, Event $event, User $target_user)
    {
        return response()->json($this->register($request, $event, $target_user));
    }

    /**
     * Zet het deel van één monteur weer open: afgeronde tijden worden gewist en een
     * annulering wordt ingetrokken. Alleen die monteur, want de knop staat op zijn
     * regel en de anderen op dezelfde afspraak houden wat ze hebben ingevuld.
     */
    public function release(EventReleaseTimesRequest $request, Event $event, User $target_user)
    {
        $execution = $event->executions()
            ->where('user_id', $target_user->id)
            ->whereIn('completion_status', [
                EventCompletionStatus::completed->value,
                EventCompletionStatus::cancelled->value,
            ])
            ->first();

        if (!$execution) {
            throw new Refusal('Er is voor ' . $target_user->name . ' niets vrij te geven op deze afspraak.');
        }

        $before = $execution->snapshot();

        $execution->update([
            'completion_status' => EventCompletionStatus::planned->value,
            'actual_start' => null,
            'actual_end' => null,
            'travel_time_minutes' => 0,
            'actual_breaktime_minutes' => null,
        ]);

        Signals::dispatch(new ExecutionTimesReleased($event, $target_user, $before, $execution->snapshot()));

        return response()->json($this->payload($execution));
    }

    private function register(EventExecutionUpdateRequest $request, Event $event, User $executor): array
    {
        $execution = $event->executionFor($executor->id);
        $before = $execution->snapshot();

        $execution->actual_start = $request->validated('actual_start');
        $execution->actual_end = $request->validated('actual_end');
        $execution->travel_time_minutes = (int) $request->validated('travel_time_minutes');
        $execution->actual_breaktime_minutes = $request->validated('actual_breaktime_minutes');
        $execution->signature_base64 = $request->validated('signature_base64');
        $execution->completion_status = EventCompletionStatus::completed->value;
        $execution->save();

        Signals::dispatch(new ExecutionTimesRegistered($event, $executor, $before, $execution->snapshot()));

        return $this->payload($execution);
    }

    private function details(EventUserExecution $execution): array
    {
        return [...$execution->only(self::REPORTED_FIELDS), 'signature_base64' => $execution->signature_base64];
    }

    private function payload(EventUserExecution $execution): array
    {
        return [...$execution->only(self::REPORTED_FIELDS), 'has_signature' => filled($execution->signature_base64)];
    }
}

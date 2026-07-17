<?php

namespace App\Http\Controllers;

use App\Enums\EventCompletionStatus;
use App\Http\Requests\EventExecutionShowOthersRequest;
use App\Http\Requests\EventExecutionTransitionRequest;
use App\Http\Requests\EventExecutionUpdateOthersRequest;
use App\Http\Requests\EventExecutionUpdateRequest;
use App\Http\Requests\EventReleaseTimesRequest;
use App\Models\Event;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class EventExecutionController extends Controller
{
    public function show(Event $event)
    {
        $execution = $event->executionFor(Auth::id());

        return response()->json([
            'completion_status' => $execution->completion_status,
            'actual_start' => $execution->actual_start,
            'actual_end' => $execution->actual_end,
            'travel_time_minutes' => $execution->travel_time_minutes,
            'signature_base64' => $execution->signature_base64,
        ]);
    }

    public function transition(EventExecutionTransitionRequest $request, Event $event)
    {
        $status = $request->validated('status');
        $execution = $event->executionFor(Auth::id());

        if ($status === EventCompletionStatus::ongoing->value) {
            $execution->actual_start = now();
        }

        if ($status === EventCompletionStatus::completed->value) {
            $execution->actual_end = now();
            $execution->signature_base64 = $request->validated('signature_base64');
        }

        $execution->completion_status = $status;
        $execution->save();

        return response()->json($this->payload($execution));
    }

    public function update(EventExecutionUpdateRequest $request, Event $event)
    {
        $execution = $event->executionFor(Auth::id());

        $execution->actual_start = $request->validated('actual_start');
        $execution->actual_end = $request->validated('actual_end');
        $execution->travel_time_minutes = (int) $request->validated('travel_time_minutes');
        $execution->signature_base64 = $request->validated('signature_base64');
        $execution->completion_status = EventCompletionStatus::completed->value;
        $execution->save();

        return response()->json($this->payload($execution));
    }

    public function showFor(EventExecutionShowOthersRequest $request, Event $event, User $target_user)
    {
        $execution = $event->executionFor($target_user->id);

        return response()->json([
            'completion_status' => $execution->completion_status,
            'actual_start' => $execution->actual_start,
            'actual_end' => $execution->actual_end,
            'travel_time_minutes' => $execution->travel_time_minutes,
            'signature_base64' => $execution->signature_base64,
        ]);
    }

    public function updateFor(EventExecutionUpdateOthersRequest $request, Event $event, User $target_user)
    {
        $execution = $event->executionFor($target_user->id);

        $execution->actual_start = $request->validated('actual_start');
        $execution->actual_end = $request->validated('actual_end');
        $execution->travel_time_minutes = (int) $request->validated('travel_time_minutes');
        $execution->signature_base64 = $request->validated('signature_base64');
        $execution->completion_status = EventCompletionStatus::completed->value;
        $execution->save();

        return response()->json($this->payload($execution));
    }

    public function release(EventReleaseTimesRequest $request, Event $event)
    {
        $event->executions()
            ->where('completion_status', EventCompletionStatus::completed->value)
            ->update([
                'completion_status' => EventCompletionStatus::planned->value,
                'actual_start' => null,
                'actual_end' => null,
                'travel_time_minutes' => 0,
            ]);

        return response()->json([
            'executions' => $event->executions()->get([
                'user_id',
                'completion_status',
                'actual_start',
                'actual_end',
                'travel_time_minutes',
            ]),
        ]);
    }

    private function payload($execution): array
    {
        return [
            'completion_status' => $execution->completion_status,
            'actual_start' => $execution->actual_start,
            'actual_end' => $execution->actual_end,
            'travel_time_minutes' => $execution->travel_time_minutes,
            'has_signature' => filled($execution->signature_base64),
        ];
    }
}

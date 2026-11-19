<?php

namespace App\Http\Controllers;

use App\Domain\Assistant\AllowanceGate;
use App\Domain\Assistant\Transcription\TranscriptionFailed;
use App\Domain\Assistant\UsageCost;
use App\Http\Requests\AssistantTranscribeRequest;
use Illuminate\Http\JsonResponse;

/**
 * Writes out a recording so it can be sent to the assistant. The text goes back
 * into the question box rather than straight to the assistant: whoever spoke
 * reads what was understood before it is acted on.
 */
class AssistantTranscriptionController extends Controller
{
    public function __invoke(AssistantTranscribeRequest $request, AllowanceGate $allowance): JsonResponse
    {
        if (tenancy()->initialized && !$allowance->hasRoom()) {
            throw new TranscriptionFailed('Het tegoed voor de assistent is op, dus inspreken kan nu niet.');
        }

        $config = config('assistant.transcription.drivers.' . config('assistant.transcription.driver'));
        $audio = $request->file('audio');

        $transcription = $config['class']::fromConfig($config)->transcribe(
            $audio->getRealPath(),
            $audio->getMimeType() ?: 'audio/webm',
            $audio->getClientOriginalName() ?: 'opname.webm',
        );

        if (tenancy()->initialized) {
            $cost = UsageCost::forTranscription(
                $transcription->model,
                $transcription->seconds,
                (float) $config['usd_per_minute'],
            );
            $allowance->record($cost, $request->user()->id);
        }

        if ($transcription->text === '') {
            throw new TranscriptionFailed('Er was niets te verstaan in de opname.');
        }

        return response()->json(['text' => $transcription->text, 'seconds' => round($transcription->seconds, 1)]);
    }
}

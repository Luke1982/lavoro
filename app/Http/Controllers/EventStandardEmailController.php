<?php

namespace App\Http\Controllers;

use App\Http\Requests\EventSendStandardEmailRequest;
use App\Http\Requests\EventStandardEmailReadRequest;
use App\Models\Event;
use App\Models\StandardEmail;
use App\Services\StandardEmailRenderer;
use App\Services\StandardEmailSender;

class EventStandardEmailController extends Controller
{
    public function index(EventStandardEmailReadRequest $request, Event $event)
    {
        return response()->json(StandardEmail::orderBy('name')->get(['id', 'name', 'subject']));
    }

    public function preview(EventStandardEmailReadRequest $request, Event $event, StandardEmail $standard_email)
    {
        $event->load(['serviceOrders.customer', 'customers']);

        return response()->json([
            'standard_email_id' => $standard_email->id,
            'to' => StandardEmailRenderer::defaultRecipient($event),
            'subject' => StandardEmailRenderer::render($standard_email->subject, $event),
            'body' => StandardEmailRenderer::render($standard_email->body, $event),
        ]);
    }

    public function send(EventSendStandardEmailRequest $request, Event $event)
    {
        $data = $request->validated();
        $standard_email = StandardEmail::with('standardAttachments')->findOrFail($data['standard_email_id']);

        StandardEmailSender::send(
            $event,
            $standard_email,
            $data['to'],
            $data['subject'],
            $data['body'],
            $data['trigger'] ?? null,
        );

        return response()->json(['message' => 'E-mail verzonden aan ' . $data['to']]);
    }

    public function history(EventStandardEmailReadRequest $request, Event $event)
    {
        $activities = $event->activities()
            ->where('category', 'email')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($activities->map(fn ($activity) => [
            'id' => $activity->id,
            'description' => $activity->description,
            'created_at' => $activity->created_at,
            'standard_email_id' => $activity->metadata['standard_email_id'] ?? null,
            'to' => $activity->metadata['to'] ?? null,
        ]));
    }
}

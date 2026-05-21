<?php

namespace App\Services\Google;

use App\Models\Event;
use App\Models\GoogleSyncedCalendar;
use App\Models\GoogleSyncedEvent;

class CalendarSyncService
{
    public function __construct(
        private GoogleCalendarApi $api,
        private EventPayloadBuilder $payload_builder,
        private IncomingChangeHandler $incoming_handler,
    ) {
    }

    public function pushEvent(Event $event, GoogleSyncedCalendar $cal): void
    {
        \Illuminate\Support\Facades\Cache::lock("push:event:{$event->id}:cal:{$cal->id}", 30)->block(10, function () use ($event, $cal) {
            $this->doPushEvent($event, $cal);
        });
    }

    private function doPushEvent(Event $event, GoogleSyncedCalendar $cal): void
    {
        $payload = $this->payload_builder->build($event);
        $mapping = GoogleSyncedEvent::where('google_synced_calendar_id', $cal->id)
            ->where('event_id', $event->id)
            ->first();

        $integration = $cal->integration;

        if ($mapping) {
            try {
                $result = $this->api->patchEvent($integration, $cal->google_calendar_id, $mapping->google_event_id, $payload);
                $mapping->update([
                    'etag' => $result->getEtag(),
                    'last_pushed_at' => now(),
                ]);
                return;
            } catch (\Google\Service\Exception $e) {
                if (!in_array($e->getCode(), [404, 410], true)) {
                    throw $e;
                }
                $mapping->delete();
                $mapping = null;
            }
        }

        $result = $this->api->insertEvent($integration, $cal->google_calendar_id, $payload);
        GoogleSyncedEvent::create([
            'google_synced_calendar_id' => $cal->id,
            'event_id' => $event->id,
            'google_event_id' => $result->getId(),
            'etag' => $result->getEtag(),
            'last_pushed_at' => now(),
        ]);
    }

    public function deleteEvent(GoogleSyncedEvent $mapping): void
    {
        $cal = $mapping->syncedCalendar;
        $this->api->deleteEvent($cal->integration, $cal->google_calendar_id, $mapping->google_event_id);
        $mapping->delete();
    }

    public function pullChanges(GoogleSyncedCalendar $cal, bool $retried = false): void
    {
        \Illuminate\Support\Facades\Cache::lock("pull:cal:{$cal->id}", 300)->block(5, function () use ($cal, $retried) {
            $this->doPullChanges($cal, $retried);
        });
    }

    private function doPullChanges(GoogleSyncedCalendar $cal, bool $retried): void
    {
        $integration = $cal->integration;
        $page_token = null;
        $sync_token = $cal->sync_token;
        $next_sync_token = null;

        try {
            do {
                $result = $this->api->listChanges($integration, $cal->google_calendar_id, $sync_token, $page_token);
                foreach ($result['items'] as $g_event) {
                    $this->incoming_handler->apply($g_event, $cal);
                }
                $page_token = $result['next_page_token'];
                $next_sync_token = $result['next_sync_token'] ?? $next_sync_token;
                $sync_token = null;
            } while ($page_token);
        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() === 410 && !$retried) {
                $cal->update(['sync_token' => null]);
                $this->pullChanges($cal, true);
                return;
            }
            throw $e;
        }

        if ($next_sync_token) {
            $cal->update([
                'sync_token' => $next_sync_token,
                'last_full_sync_at' => now(),
            ]);
        }
    }
}

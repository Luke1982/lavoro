<?php

namespace App\Services\Google;

use App\Jobs\Google\PushEventJob;
use App\Models\Event;
use App\Models\EventType;
use App\Models\GoogleSyncedCalendar;
use App\Models\GoogleSyncedEvent;
use App\Models\User;
use Carbon\Carbon;
use Google\Service\Calendar\Event as GoogleEvent;
use Google\Service\Calendar\EventDateTime;
use Illuminate\Support\Facades\Log;

class IncomingChangeHandler
{
    public function __construct(
        private GoogleCalendarApi $api,
        private EventPayloadBuilder $payload_builder,
    ) {}

    public function apply(GoogleEvent $g_event, GoogleSyncedCalendar $cal): void
    {
        $actor = $cal->integration->user;
        $mapping = GoogleSyncedEvent::where('google_synced_calendar_id', $cal->id)
            ->where('google_event_id', $g_event->getId())
            ->first();

        if ($mapping && $mapping->etag === $g_event->getEtag()) {
            return;
        }

        if ($mapping) {
            $this->applyToExisting($g_event, $cal, $mapping, $actor);
        } else {
            $this->applyToNew($g_event, $cal, $actor);
        }
    }

    private function applyToExisting(GoogleEvent $g_event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping, User $actor): void
    {
        $event = Event::withTrashed()->find($mapping->event_id);
        if (!$event) {
            $this->api->deleteEvent($cal->integration, $cal->google_calendar_id, $g_event->getId());
            $mapping->delete();

            return;
        }

        if ($g_event->getStatus() === 'cancelled') {
            $this->handleDeletion($event, $cal, $mapping, $actor);

            return;
        }

        if ($event->trashed()) {
            $this->handleUncancel($event, $cal, $mapping, $actor);

            return;
        }

        $changed = $this->detectFieldChanges($event, $g_event);

        $this->processTimeChange($changed, $event, $cal, $mapping, $actor);
        $this->processTextChanges($changed, $event, $cal, $mapping, $actor);
    }

    private function handleUncancel(Event $event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping, User $actor): void
    {
        if ($actor->can('update', $event)) {
            $event->restore();

            return;
        }
        $this->api->deleteEvent($cal->integration, $cal->google_calendar_id, $mapping->google_event_id);
        $this->logRevert($event, $actor, 'uncancel-unauthorized');
    }

    private function handleDeletion(Event $event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping, User $actor): void
    {
        if ($actor->can('delete', $event)) {
            $event->delete();

            return;
        }
        $payload = $this->payload_builder->build($event);
        $result = $this->api->insertEvent($cal->integration, $cal->google_calendar_id, $payload);
        $mapping->update([
            'google_event_id' => $result->getId(),
            'etag' => $result->getEtag(),
            'last_pushed_at' => now(),
        ]);
        $this->logRevert($event, $actor, 'delete');
    }

    private function processTimeChange(array $changed, Event $event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping, User $actor): void
    {
        if (!$changed['time']) {
            return;
        }
        if ($actor->can('update', $event)) {
            $event->update([
                'start' => $changed['new_start'],
                'end' => $changed['new_end'],
            ]);

            return;
        }
        $this->correctivePush($event, $cal, $mapping);
        $this->logRevert($event, $actor, 'time');
    }

    private function processTextChanges(array $changed, Event $event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping, User $actor): void
    {
        if (!$changed['name'] && !$changed['description'] && !$changed['location']) {
            return;
        }

        if ($event->origin === 'lavoro') {
            $this->correctivePush($event, $cal, $mapping);
            $this->logRevert($event, $actor, 'text-lavoro-origin');

            return;
        }

        if (!$actor->can('update', $event)) {
            $this->correctivePush($event, $cal, $mapping);
            $this->logRevert($event, $actor, 'text-unauthorized');

            return;
        }

        /**
         * Location is owned by Lavoro and is never accepted from Google: it may
         * be derived from a linked location, and letting a calendar edit win
         * would silently diverge from the werkbon/appointment it belongs to.
         * Name and description are still fair game.
         */
        $event->update([
            'name' => $changed['new_name'],
            'description' => $changed['new_description'],
        ]);

        if ($changed['location']) {
            $this->correctivePush($event, $cal, $mapping);
            $this->logRevert($event, $actor, 'location-owned-by-lavoro');
        }
    }

    private function applyToNew(GoogleEvent $g_event, GoogleSyncedCalendar $cal, User $actor): void
    {
        if ($g_event->getStatus() === 'cancelled') {
            return;
        }

        $owner_user = User::find($cal->owner_user_id);
        if (!$owner_user) {
            $this->api->deleteEvent($cal->integration, $cal->google_calendar_id, $g_event->getId());
            Log::warning('Refused incoming Google event — calendar owner user not found', [
                'cal_id' => $cal->id,
            ]);

            return;
        }

        $authorized = $actor->isAdmin()
            || ($owner_user->id === $actor->id && $actor->hasPermission('event.create'))
            || $actor->hasPermission('event.create_others');

        if (!$authorized) {
            $this->api->deleteEvent($cal->integration, $cal->google_calendar_id, $g_event->getId());
            Log::info('Refused incoming new Google event (no permission)', [
                'cal_id' => $cal->id,
                'actor_id' => $actor->id,
            ]);

            return;
        }

        $start = $this->parseGoogleDateTime($g_event->getStart())->utc();
        $end = $this->parseGoogleDateTime($g_event->getEnd())->utc();

        $event_type_id = config('google.default_event_type_id');
        if (!$event_type_id) {
            $event_type = EventType::first();
            if (!$event_type) {
                $this->api->deleteEvent($cal->integration, $cal->google_calendar_id, $g_event->getId());
                Log::warning('Refused incoming Google event — no EventType available', [
                    'cal_id' => $cal->id,
                ]);

                return;
            }
            $event_type_id = $event_type->id;
        }

        $event = Event::withoutEvents(function () use (
            $g_event,
            $start,
            $end,
            $event_type_id,
            $owner_user
        ) {
            $event = Event::create([
                'name' => $g_event->getSummary() ?? '(geen titel)',
                'description' => $g_event->getDescription(),
                'location' => $g_event->getLocation(),
                'start' => $start,
                'end' => $end,
                'status' => 'Gepland',
                'event_type_id' => $event_type_id,
                'origin' => 'google',
            ]);
            $event->owners()->attach($owner_user->id, ['type' => 'owner']);
            $event->executingUsers()->attach($owner_user->id, ['type' => 'executing']);

            return $event;
        });

        GoogleSyncedEvent::create([
            'google_synced_calendar_id' => $cal->id,
            'event_id' => $event->id,
            'google_event_id' => $g_event->getId(),
            'etag' => $g_event->getEtag(),
            'last_pushed_at' => now(),
        ]);

        PushEventJob::dispatch($event->id);
    }

    private function detectFieldChanges(Event $event, GoogleEvent $g_event): array
    {
        $new_start = $this->parseGoogleDateTime($g_event->getStart())->utc();
        $new_end = $this->parseGoogleDateTime($g_event->getEnd())->utc();
        $new_name = $g_event->getSummary();
        $new_description = $g_event->getDescription();
        $new_location = $g_event->getLocation();

        return [
            'time' => !$event->start->equalTo($new_start) || !$event->end->equalTo($new_end),
            'name' => $event->name !== $new_name,
            'description' => $event->description !== $new_description,
            'location' => $event->location !== $new_location,
            'new_start' => $new_start,
            'new_end' => $new_end,
            'new_name' => $new_name,
            'new_description' => $new_description,
            'new_location' => $new_location,
        ];
    }

    private function correctivePush(Event $event, GoogleSyncedCalendar $cal, GoogleSyncedEvent $mapping): void
    {
        $payload = $this->payload_builder->build($event);
        $result = $this->api->patchEvent($cal->integration, $cal->google_calendar_id, $mapping->google_event_id, $payload);
        $mapping->update([
            'etag' => $result->getEtag(),
            'last_pushed_at' => now(),
        ]);
    }

    private function parseGoogleDateTime(EventDateTime $dt): Carbon
    {
        if ($dt->getDateTime()) {
            return Carbon::parse($dt->getDateTime());
        }

        return Carbon::parse($dt->getDate() . 'T00:00:00', 'Europe/Amsterdam');
    }

    private function logRevert(Event $event, User $actor, string $kind): void
    {
        Log::info('Google sync: corrective push applied', [
            'event_id' => $event->id,
            'actor_id' => $actor->id,
            'kind' => $kind,
        ]);
    }
}

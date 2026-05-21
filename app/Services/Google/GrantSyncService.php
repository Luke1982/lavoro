<?php

namespace App\Services\Google;

use App\Jobs\Google\BackfillCalendarJob;
use App\Models\CalendarGrant;
use App\Models\GoogleSyncedCalendar;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GrantSyncService
{
    public function __construct(private GoogleCalendarApi $api)
    {
    }

    public function onGrantCreated(CalendarGrant $grant): void
    {
        $viewer = $grant->viewerUser;
        $owner = $grant->ownerUser;
        $integration = $viewer->googleCalendarIntegration;

        if (!$integration || $integration->isDisabled()) {
            return;
        }

        $existing = GoogleSyncedCalendar::where('google_calendar_integration_id', $integration->id)
            ->where('owner_user_id', $owner->id)
            ->first();
        if ($existing) {
            return;
        }

        $summary = str_replace(':name', $owner->name, config('google.calendar_summary_granted_template'));
        $google_cal = $this->api->createCalendar($integration, $summary);

        try {
            $cal = DB::transaction(function () use ($integration, $owner, $google_cal) {
                return GoogleSyncedCalendar::create([
                    'google_calendar_integration_id' => $integration->id,
                    'owner_user_id' => $owner->id,
                    'google_calendar_id' => $google_cal->getId(),
                    'summary' => $google_cal->getSummary(),
                ]);
            });
        } catch (\Throwable $e) {
            try {
                $this->api->deleteCalendar($integration, $google_cal->getId());
            } catch (\Throwable $ignored) {
            }
            throw $e;
        }

        BackfillCalendarJob::dispatch($cal->id);
    }

    public function onGrantRevoked(CalendarGrant $grant): void
    {
        $viewer = $grant->viewerUser;
        $owner = $grant->ownerUser;
        $integration = $viewer->googleCalendarIntegration;

        if (!$integration) {
            return;
        }

        $cal = GoogleSyncedCalendar::where('google_calendar_integration_id', $integration->id)
            ->where('owner_user_id', $owner->id)
            ->first();
        if (!$cal) {
            return;
        }

        if ($cal->watch_channel_id && $cal->watch_resource_id) {
            $this->api->stopWatch($integration, $cal->watch_channel_id, $cal->watch_resource_id);
        }

        try {
            $this->api->deleteCalendar($integration, $cal->google_calendar_id);
        } catch (\Throwable $e) {
            Log::warning('deleteCalendar failed during grant revoke', ['error' => $e->getMessage()]);
        }

        $cal->delete();
    }
}

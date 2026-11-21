<?php

namespace App\Services\Google;

use App\Jobs\Google\BackfillCalendarJob;
use App\Models\CalendarGrant;
use App\Models\GoogleCalendarIntegration;
use App\Models\GoogleSyncedCalendar;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class GrantSyncService
{
    public function __construct(private GoogleCalendarApi $api)
    {
    }

    /**
     * Both people are read past every scope: in the customer's request a
     * MajorLabel account does not resolve through the grant. Nothing is made
     * for someone who has left.
     */
    public function onGrantCreated(CalendarGrant $grant): void
    {
        $viewer = User::withoutGlobalScopes()->find($grant->viewer_user_id);
        $owner = User::withoutGlobalScopes()->find($grant->owner_user_id);

        if (!$viewer || !$owner || $viewer->trashed() || $owner->trashed()) {
            return;
        }

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

    /**
     * Runs after the grant is gone. Whatever Google says, the link to the
     * calendar is removed here: as long as it exists the owner's appointments
     * keep being pushed into it, to someone who may no longer see them.
     *
     * Goes by the ids on the grant and needs neither person: either may have
     * left, or be an account the customer's request does not see, and the
     * calendar has to go all the same.
     */
    public function onGrantRevoked(CalendarGrant $grant): void
    {
        $integration = GoogleCalendarIntegration::where('user_id', $grant->viewer_user_id)->first();

        if (!$integration) {
            return;
        }

        $cal = GoogleSyncedCalendar::where('google_calendar_integration_id', $integration->id)
            ->where('owner_user_id', $grant->owner_user_id)
            ->first();
        if (!$cal) {
            return;
        }

        if ($cal->watch_channel_id && $cal->watch_resource_id) {
            try {
                $this->api->stopWatch($integration, $cal->watch_channel_id, $cal->watch_resource_id);
            } catch (\Throwable $e) {
                Log::warning('stopWatch failed during grant revoke', ['error' => $e->getMessage()]);
            }
        }

        try {
            $this->api->deleteCalendar($integration, $cal->google_calendar_id);
        } catch (\Throwable $e) {
            Log::warning('deleteCalendar failed during grant revoke', ['error' => $e->getMessage()]);
        }

        $cal->delete();
    }
}

<?php

namespace App\Jobs\Google;

use App\Models\DayRoute;
use App\Models\GoogleSyncedCalendar;
use App\Services\Google\DayRoutePayloadBuilder;
use App\Services\Google\GoogleCalendarApi;
use Google\Service\Exception as GoogleException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;

/**
 * Puts a route in every Google calendar that shows its person's day, or brings
 * the item there up to date. Each item is written down as soon as it exists,
 * so a retry after a failure halfway updates what it made instead of making it
 * twice.
 */
class PushDayRouteJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public array $backoff = [60, 300, 1800, 3600, 7200];

    public function __construct(public int $day_route_id) {}

    public function handle(GoogleCalendarApi $api, DayRoutePayloadBuilder $payload_builder): void
    {
        Cache::lock('push:day_route:' . $this->day_route_id, 60)->block(10, function () use ($api, $payload_builder) {
            $route = DayRoute::find($this->day_route_id);

            if (!$route) {
                return;
            }

            $payload = $payload_builder->build($route);
            $calendars = GoogleSyncedCalendar::query()
                ->where('owner_user_id', $route->user_id)
                ->whereHas('integration', fn ($integration) => $integration->whereNull('disabled_at'))
                ->with('integration')
                ->get();

            foreach ($calendars as $calendar) {
                $google_event_id = $this->push($api, $calendar, $route->google_items[$calendar->id] ?? null, $payload);
                $route->google_items = [$calendar->id => $google_event_id] + ($route->google_items ?? []);
                $route->save();
            }
        });
    }

    /** The item's id in that calendar: the same one when it could be updated, a new one when it was gone. */
    private function push(
        GoogleCalendarApi $api,
        GoogleSyncedCalendar $calendar,
        ?string $google_event_id,
        array $payload,
    ): string {
        $integration = $calendar->integration;

        if ($google_event_id !== null) {
            try {
                return $api->patchEvent($integration, $calendar->google_calendar_id, $google_event_id, $payload)->getId();
            } catch (GoogleException $exception) {
                if (!in_array($exception->getCode(), [404, 410], true)) {
                    throw $exception;
                }
            }
        }

        return $api->insertEvent($integration, $calendar->google_calendar_id, $payload)->getId();
    }
}

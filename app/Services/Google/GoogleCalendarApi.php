<?php

namespace App\Services\Google;

use App\Models\GoogleCalendarIntegration;
use Google\Service\Calendar;
use Google\Service\Calendar\Calendar as CalendarResource;
use Google\Service\Calendar\Channel;
use Google\Service\Calendar\Event as GoogleEvent;
use Illuminate\Support\Facades\Log;

class GoogleCalendarApi
{
    public function __construct(private GoogleClientFactory $client_factory)
    {
    }

    private function service(GoogleCalendarIntegration $integration): Calendar
    {
        return new Calendar($this->client_factory->clientFor($integration));
    }

    public function createCalendar(GoogleCalendarIntegration $integration, string $summary): CalendarResource
    {
        $cal = new CalendarResource(['summary' => $summary, 'timeZone' => 'Europe/Amsterdam']);
        return $this->retry(fn () => $this->service($integration)->calendars->insert($cal));
    }

    public function deleteCalendar(GoogleCalendarIntegration $integration, string $google_calendar_id): void
    {
        $this->retry(function () use ($integration, $google_calendar_id) {
            $this->service($integration)->calendars->delete($google_calendar_id);
        });
    }

    public function insertEvent(GoogleCalendarIntegration $integration, string $google_calendar_id, array $payload): GoogleEvent
    {
        $event = new GoogleEvent($payload);
        return $this->retry(fn () => $this->service($integration)->events->insert($google_calendar_id, $event));
    }

    public function patchEvent(GoogleCalendarIntegration $integration, string $google_calendar_id, string $google_event_id, array $payload): GoogleEvent
    {
        $event = new GoogleEvent($payload);
        return $this->retry(fn () => $this->service($integration)->events->patch($google_calendar_id, $google_event_id, $event));
    }

    public function deleteEvent(GoogleCalendarIntegration $integration, string $google_calendar_id, string $google_event_id): void
    {
        try {
            $this->retry(function () use ($integration, $google_calendar_id, $google_event_id) {
                $this->service($integration)->events->delete($google_calendar_id, $google_event_id);
            });
        } catch (\Google\Service\Exception $e) {
            if (in_array($e->getCode(), [404, 410], true)) {
                return;
            }
            throw $e;
        }
    }

    public function listChanges(GoogleCalendarIntegration $integration, string $google_calendar_id, ?string $sync_token, ?string $page_token = null): array
    {
        $params = ['showDeleted' => true, 'maxResults' => 250];
        if ($sync_token) {
            $params['syncToken'] = $sync_token;
        } else {
            $params['timeMin'] = now()->subDays(config('google.sync_lookback_days'))->toRfc3339String();
        }
        if ($page_token) {
            $params['pageToken'] = $page_token;
        }
        $result = $this->retry(fn () => $this->service($integration)->events->listEvents($google_calendar_id, $params));
        return [
            'items' => $result->getItems(),
            'next_page_token' => $result->getNextPageToken(),
            'next_sync_token' => $result->getNextSyncToken(),
        ];
    }

    public function watchCalendar(GoogleCalendarIntegration $integration, string $google_calendar_id, string $channel_id, string $token, string $address, int $ttl_seconds): array
    {
        $channel = new Channel([
            'id' => $channel_id,
            'type' => 'web_hook',
            'address' => $address,
            'token' => $token,
            'params' => ['ttl' => (string) $ttl_seconds],
        ]);
        $result = $this->retry(fn () => $this->service($integration)->events->watch($google_calendar_id, $channel));
        return [
            'resource_id' => $result->getResourceId(),
            'expiration' => $result->getExpiration(),
        ];
    }

    public function stopWatch(GoogleCalendarIntegration $integration, string $channel_id, string $resource_id): void
    {
        try {
            $channel = new Channel(['id' => $channel_id, 'resourceId' => $resource_id]);
            $this->service($integration)->channels->stop($channel);
        } catch (\Throwable $e) {
            Log::info('stopWatch failed (non-fatal)', ['error' => $e->getMessage()]);
        }
    }

    private function retry(callable $fn, int $attempts = 4)
    {
        $delay_ms = 1000;
        $last = null;
        for ($i = 0; $i < $attempts; $i++) {
            try {
                return $fn();
            } catch (\Google\Service\Exception $e) {
                $code = $e->getCode();
                if (!in_array($code, [429, 500, 502, 503, 504], true)) {
                    throw $e;
                }
                $last = $e;
                usleep(($delay_ms + random_int(0, 250)) * 1000);
                $delay_ms *= 2;
            }
        }
        throw $last;
    }
}

<?php

namespace App\Http\Controllers;

use App\Jobs\Google\PullCalendarChangesJob;
use App\Models\GoogleSyncedCalendar;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GoogleWebhookController extends Controller
{
    public function handle(Request $request): Response
    {
        $channel_id = $request->header('X-Goog-Channel-Id');
        $channel_token = $request->header('X-Goog-Channel-Token');
        $resource_id = $request->header('X-Goog-Resource-Id');
        $state = $request->header('X-Goog-Resource-State');

        if (!$channel_id || !$resource_id) {
            return response('Bad Request', 400);
        }

        $cal = GoogleSyncedCalendar::where('watch_channel_id', $channel_id)->first();
        if (!$cal) {
            return response('Unknown channel', 404);
        }

        if (!hash_equals((string) $cal->watch_channel_token, (string) $channel_token)) {
            return response('Forbidden', 403);
        }

        if ((string) $cal->watch_resource_id !== (string) $resource_id) {
            return response('Forbidden', 403);
        }

        if ($state === 'sync') {
            return response('OK', 200);
        }

        PullCalendarChangesJob::dispatch($cal->id);
        return response('OK', 200);
    }
}

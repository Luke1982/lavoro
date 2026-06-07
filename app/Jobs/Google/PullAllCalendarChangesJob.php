<?php

namespace App\Jobs\Google;

use App\Models\GoogleSyncedCalendar;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PullAllCalendarChangesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        if (!config('google.webhook_enabled')) {
            return;
        }

        GoogleSyncedCalendar::whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
            ->whereNotNull('google_calendar_id')
            ->pluck('id')
            ->each(fn ($id) => PullCalendarChangesJob::dispatch($id));
    }
}

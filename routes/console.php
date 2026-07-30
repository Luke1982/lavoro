<?php

use App\Jobs\Google\PullCalendarChangesJob;
use App\Models\GoogleSyncedCalendar;
use App\Models\LocationPing;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function () {
    GoogleSyncedCalendar::query()
        ->whereHas('integration', fn ($q) => $q->whereNull('disabled_at'))
        ->pluck('id')
        ->each(fn ($id) => PullCalendarChangesJob::dispatch($id));
})->everyFiveMinutes()->name('google-pull-changes')->withoutOverlapping();

Schedule::job(new \App\Jobs\Google\RenewWatchChannelsJob())
    ->hourly()
    ->name('google-renew-watches')
    ->withoutOverlapping();

Schedule::call(function () {
    LocationPing::where('recorded_at', '<', now()->subDay())->delete();
})->hourly()->name('prune-location-pings')->withoutOverlapping();

Schedule::command('maintenancecontracts:generate-serviceorders')
    ->hourly()
    ->name('maintenancecontracts-generate-serviceorders')
    ->withoutOverlapping();

/**
 * The command has existed since the assistant did and was scheduled nowhere, so
 * a transcript of everybody's working day was kept for ever while the thing that
 * trims them was never once invoked. Its own default says six months, which was
 * an intention rather than a fact.
 */
Schedule::command('assistant:prune')
    ->dailyAt('03:20')
    ->name('assistant-prune-questions')
    ->withoutOverlapping();

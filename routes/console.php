<?php

use App\Jobs\DeleteOrphanedImagesJob;
use App\Jobs\Google\PullCalendarChangesJob;
use App\Jobs\Google\RenewWatchChannelsJob;
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

Schedule::job(new RenewWatchChannelsJob)
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

/**
 * A deletion already clears its photos. This catches the ones deleted without a
 * signal: a product, a brand, an event type or a check takes records with photos
 * along in the database, and none of those announce it. It also clears what
 * deletions left behind before any of this existed.
 */
Schedule::job(new DeleteOrphanedImagesJob)
    ->dailyAt('03:25')
    ->name('delete-orphaned-images')
    ->withoutOverlapping();

/**
 * Kijkt of er nog uren openstaan van afspraken van gisteren of eerder. 's Ochtends
 * vroeg, zodat het een herinnering is voor vandaag en geen storing van gisteravond.
 */
Schedule::command('notifications:missing-times')
    ->dailyAt('07:00')
    ->name('notifications-missing-times')
    ->withoutOverlapping();

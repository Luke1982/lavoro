<?php

use App\Jobs\Google\PullCalendarChangesJob;
use App\Models\GoogleSyncedCalendar;
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

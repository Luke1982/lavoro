<?php

use App\Jobs\GenerateMaintenanceContractServiceOrdersJob;
use App\Jobs\Google\DispatchTenantCalendarPullsJob;
use App\Jobs\Google\RenewWatchChannelsJob;
use App\Jobs\NotifyMissingExecutionTimesJob;
use App\Jobs\PruneAssistantQuestionsJob;
use App\Jobs\PruneLocationPingsJob;
use App\Jobs\ReconcileStorageUsageJob;
use App\Jobs\ReinstallDemoTenantJob;
use App\Models\Tenant;
use App\Support\Tenancy;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Every tick does one thing per tenant: check whether its database opens, and
 * then write one row in the central jobs table. No delete, no query whose cost
 * grows with how much data a customer has. The work itself happens in the job,
 * which gets the right tenant through QueueTenancyBootstrapper.
 *
 * That one check up front is there because a customer with a vanished database
 * otherwise makes every task fall over: on production that produced 1313 failed
 * jobs, all from the same customer, with everything that was really wrong among
 * them.
 */
$forEachTenant = fn (callable $dispatch) => Tenancy::forEachReachable($dispatch);

Schedule::call(fn () => $forEachTenant(fn () => DispatchTenantCalendarPullsJob::dispatch()))
    ->everyFiveMinutes()->name('google-pull-changes')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => RenewWatchChannelsJob::dispatch()))
    ->hourly()->name('google-renew-watches')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => PruneLocationPingsJob::dispatch()))
    ->hourly()->name('prune-location-pings')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => GenerateMaintenanceContractServiceOrdersJob::dispatch()))
    ->hourly()->name('maintenancecontracts-generate-serviceorders')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => PruneAssistantQuestionsJob::dispatch()))
    ->dailyAt('03:20')->name('assistant-prune-questions')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => NotifyMissingExecutionTimesJob::dispatch()))
    ->dailyAt('07:00')->name('notifications-missing-times')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => ReconcileStorageUsageJob::dispatch()))
    ->dailyAt('03:30')->name('reconcile-storage-usage')->withoutOverlapping();

/**
 * Hourly and not daily: a customer who started on the 12th should get their
 * invoice on the 12th, and with one round a day that shifts to whatever hour
 * the cron happens to sit at. The round skips whatever already has an invoice,
 * so 24 ticks a day cost nothing 23 times.
 *
 * Only creating, not sending: someone should look at it first.
 */
Schedule::command('invoices:issue')
    ->hourly()->name('invoices-issue')->withoutOverlapping();

/**
 * The demo tenant, thrown away and rebuilt every night once demo:install has
 * made it, so every demo starts from the same clean state around today. Only
 * while one exists: an installation without a demo does not grow one.
 */
Schedule::job(new ReinstallDemoTenantJob)
    ->dailyAt('04:00')->name('demo-reinstall')->withoutOverlapping()
    ->when(fn () => Tenant::on('central')->get()->contains(fn (Tenant $tenant) => $tenant->isDemo()));

/** The cron cannot be checked from PHP; the scheduler proves it itself. */
Schedule::call(fn () => cache()->forever('scheduler_heartbeat', now()->timestamp))
    ->everyFiveMinutes()->name('scheduler-heartbeat');

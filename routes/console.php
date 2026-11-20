<?php

use App\Jobs\DeleteOrphanedImagesJob;
use App\Jobs\ExpireQuotesJob;
use App\Jobs\FollowUpQuotesJob;
use App\Jobs\GenerateMaintenanceContractServiceOrdersJob;
use App\Jobs\Google\DispatchTenantCalendarPullsJob;
use App\Jobs\Google\RenewWatchChannelsJob;
use App\Jobs\Integrations\RenewIntegrationWebhooksJob;
use App\Jobs\Integrations\SyncIntegrationsJob;
use App\Jobs\NotifyMissingExecutionTimesJob;
use App\Jobs\NotifyOverdueMilestonesJob;
use App\Jobs\PruneAssistantQuestionsJob;
use App\Jobs\PruneLocationPingsJob;
use App\Jobs\ReconcileStorageUsageJob;
use App\Jobs\ReinstallDemoTenantJob;
use App\Jobs\SendAppointmentRemindersJob;
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

/**
 * Customers and materials from every linked system (SnelStart, e-Boekhouden,
 * ...), both ways, for every customer that has one; the job looks that up
 * itself. Nightly everything, every quarter of an hour only what a system can
 * report as changed. Webhooks are renewed hourly, before they run out.
 */
Schedule::call(fn () => $forEachTenant(fn () => SyncIntegrationsJob::dispatch()))
    ->dailyAt('03:00')->name('integrations-sync')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => SyncIntegrationsJob::dispatch(frequent: true)))
    ->everyFifteenMinutes()->name('integrations-sync-changes')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => RenewIntegrationWebhooksJob::dispatch()))
    ->hourly()->name('integrations-renew-webhooks')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => PruneAssistantQuestionsJob::dispatch()))
    ->dailyAt('03:20')->name('assistant-prune-questions')->withoutOverlapping();

/**
 * A deletion already clears its photos. This catches the ones deleted without a
 * signal: a product, a brand, an event type or a check takes records with photos
 * along in the database, and none of those announce it. It also clears what
 * deletions left behind before any of this existed.
 */
Schedule::call(fn () => $forEachTenant(fn () => DeleteOrphanedImagesJob::dispatch()))
    ->dailyAt('03:25')->name('delete-orphaned-images')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => NotifyMissingExecutionTimesJob::dispatch()))
    ->dailyAt('07:00')->name('notifications-missing-times')->withoutOverlapping();

Schedule::call(fn () => $forEachTenant(fn () => NotifyOverdueMilestonesJob::dispatch()))
    ->dailyAt('07:05')->name('notifications-overdue-milestones')->withoutOverlapping();

/** Only where the customer has the quotes module: without it there is nothing to lapse. */
Schedule::call(fn () => $forEachTenant(function () {
    if (tenancy()->tenant->hasModule('quotes')) {
        ExpireQuotesJob::dispatch();
    }
}))->dailyAt('06:30')->name('quotes-expire')->withoutOverlapping();

/** After the lapsing, so a quote that ran out yesterday is not also reminded today. */
Schedule::call(fn () => $forEachTenant(function () {
    if (tenancy()->tenant->hasModule('quotes')) {
        FollowUpQuotesJob::dispatch();
    }
}))->dailyAt('08:30')->name('quotes-follow-up')->withoutOverlapping();

/**
 * Every five minutes, so a reminder set for an hour lands between 55 and 60
 * minutes ahead. It names the clock time, not the countdown, so it is never wrong.
 * The overlap lock expires after ten minutes rather than the default day: a
 * scheduler killed halfway would otherwise silence every reminder until tomorrow.
 */
Schedule::call(fn () => $forEachTenant(fn () => SendAppointmentRemindersJob::dispatch()))
    ->everyFiveMinutes()->name('appointment-reminders')->withoutOverlapping(10);

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

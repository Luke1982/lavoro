<?php

use App\Jobs\GenerateMaintenanceContractServiceOrdersJob;
use App\Jobs\Google\DispatchTenantCalendarPullsJob;
use App\Jobs\Google\RenewWatchChannelsJob;
use App\Jobs\NotifyMissingExecutionTimesJob;
use App\Jobs\PruneAssistantQuestionsJob;
use App\Jobs\PruneLocationPingsJob;
use App\Jobs\ReconcileStorageUsageJob;
use App\Support\Tenancy;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/**
 * Elke tik doet per tenant één ding: kijken of zijn database opengaat, en dan
 * één rij in de centrale jobs-tabel. Geen delete, geen query waarvan de kosten
 * meegroeien met hoeveel data een klant heeft. Het werk zelf gebeurt in de
 * job, die door QueueTenancyBootstrapper de juiste tenant meekrijgt.
 *
 * Die ene controle vooraf staat er omdat een klant met een verdwenen database
 * anders elke taak laat omvallen: op productie leverde dat 1313 mislukte taken
 * op, allemaal van dezelfde klant, met alles wat er echt mis was ertussen.
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
 * Uurlijks en niet dagelijks: een klant die op de 12e begon hoort op de 12e
 * zijn factuur te krijgen, en met één ronde per dag verschuift dat naar het
 * uur waarop de cron toevallig staat. De ronde slaat over wat al een factuur
 * heeft, dus 24 tikken per dag kosten 23 keer niets.
 *
 * Alleen aanmaken, niet versturen: er hoort eerst iemand naar te kijken.
 */
Schedule::command('invoices:issue')
    ->hourly()->name('invoices-issue')->withoutOverlapping();

/** De cron kan niet vanuit PHP gecontroleerd worden; de planner bewijst het zelf. */
Schedule::call(fn () => cache()->forever('scheduler_heartbeat', now()->timestamp))
    ->everyFiveMinutes()->name('scheduler-heartbeat');

<?php

namespace App\Console;

use App\Jobs\Google\PullAllCalendarChangesJob;
use App\Jobs\Google\RenewWatchChannelsJob;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        $schedule->job(RenewWatchChannelsJob::class)->dailyAt('02:00')->withoutOverlapping();
        $schedule->job(PullAllCalendarChangesJob::class)->everyThreeMinutes()->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__ . '/Commands');
    }
}

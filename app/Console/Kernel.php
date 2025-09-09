<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Nightly sync at 03:00
        $schedule->command('snelstart:fetch-relaties')->dailyAt('03:00')->withoutOverlapping();
        $schedule->command('snelstart:fetch-artikelen')->dailyAt('03:10')->withoutOverlapping();
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
                $this->load(__DIR__ . '/Commands');
    }
}

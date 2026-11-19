<?php

namespace App\Jobs;

use App\Domain\Planning\Clock;
use App\Domain\Signals\Projects\MilestoneOverdue;
use App\Domain\Signals\Signals;
use App\Models\ProjectMilestone;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

/**
 * Every morning: each milestone of a running project that passed its planned date
 * yesterday or earlier without being reached is said once. Moving its date clears
 * the mark, so a milestone that slips again is said again.
 */
class NotifyOverdueMilestonesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(): void
    {
        ProjectMilestone::query()
            ->whereNull('actual_date')
            ->whereNull('overdue_notified_at')
            ->whereDate('projected_date', '<', Clock::today())
            ->whereHas('project', fn ($query) => $query->active())
            ->with('project')
            ->lazyById()
            ->each(fn (ProjectMilestone $milestone) => DB::transaction(function () use ($milestone) {
                $milestone->update(['overdue_notified_at' => now()]);

                Signals::dispatch(new MilestoneOverdue($milestone, $milestone->project));
            }));
    }
}

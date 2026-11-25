<?php

namespace App\Jobs;

use App\Models\AssistantFilter;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/** One tenant's one-time filters from the assistant, once they are as old as the conversations it keeps. */
class PruneAssistantFiltersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        AssistantFilter::prune();
    }
}

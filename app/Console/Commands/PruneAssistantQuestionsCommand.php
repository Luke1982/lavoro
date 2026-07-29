<?php

namespace App\Console\Commands;

use App\Models\AssistantQuestion;
use Illuminate\Console\Command;

/**
 * Throws away transcripts past their keeping.
 *
 * These rows are small — a question and an answer are less than one tool call is
 * already storing — so this is about how long a record of somebody's working day
 * should exist, not about disk.
 *
 * Nothing runs it on its own. Scheduler entries in this application need a
 * server-level cron that is not wired up, so until it is, this is a command
 * somebody runs.
 */
class PruneAssistantQuestionsCommand extends Command
{
    protected $signature = 'assistant:prune {--months=6 : Keep this many months} {--dry-run}';

    protected $description = 'Verwijdert opgeslagen assistentvragen ouder dan het bewaartermijn';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $cutoff = now()->subMonths($months);

        $query = AssistantQuestion::query()->where('created_at', '<', $cutoff);
        $count = $query->count();

        if ($this->option('dry-run')) {
            $this->info($count . ' vragen zouden verdwijnen (ouder dan ' . $cutoff->toDateString() . ').');

            return self::SUCCESS;
        }

        $query->delete();
        $this->info($count . ' vragen verwijderd (ouder dan ' . $cutoff->toDateString() . ').');

        return self::SUCCESS;
    }
}

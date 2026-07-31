<?php

namespace App\Console\Commands;

use App\Models\AssistantConversationFact;
use App\Models\AssistantQuestion;
use Carbon\CarbonInterface;
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

    protected $description = 'Verwijdert opgeslagen assistentvragen en gespreksnotities ouder dan het bewaartermijn';

    public function handle(): int
    {
        $months = max(1, (int) $this->option('months'));
        $cutoff = now()->subMonths($months);

        $questions = AssistantQuestion::query()->where('created_at', '<', $cutoff);

        /**
         * The notes a conversation kept go with the conversation. They hold a
         * customer's name and number, so leaving them behind would keep the part
         * worth keeping least for ever, under a retention rule that only ever
         * looked at the transcript beside them.
         */
        $facts = AssistantConversationFact::query()->where('updated_at', '<', $cutoff);

        $counts = [$questions->count(), $facts->count()];

        if ($this->option('dry-run')) {
            $this->info($this->lineFor($counts, $cutoff, 'zouden verdwijnen'));

            return self::SUCCESS;
        }

        $questions->delete();
        $facts->delete();

        $this->info($this->lineFor($counts, $cutoff, 'verwijderd'));

        return self::SUCCESS;
    }

    /** @param array{0: int, 1: int} $counts */
    private function lineFor(array $counts, CarbonInterface $cutoff, string $verb): string
    {
        return $counts[0] . ' vragen en ' . $counts[1] . ' gespreksnotities ' . $verb
            . ' (ouder dan ' . $cutoff->toDateString() . ').';
    }
}

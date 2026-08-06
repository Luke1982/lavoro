<?php

namespace App\Console\Commands;

use App\Domain\Assistant\ConversationPhotos;
use App\Models\AssistantConversationFact;
use App\Models\AssistantQuestion;
use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

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

        /**
         * The reported conversations go too. They are the fullest copy of any of
         * this — the questions, the answers, and what the tools were handed —
         * so keeping them longer than the rows they were written from would put
         * the retention rule exactly the wrong way round.
         */
        $reports = $this->reportsOlderThan($cutoff);

        $counts = [$questions->count(), $facts->count(), count($reports)];

        if ($this->option('dry-run')) {
            $this->info($this->lineFor($counts, $cutoff, 'zouden verdwijnen'));

            return self::SUCCESS;
        }

        $questions->delete();
        $facts->delete();

        /**
         * Parked photos get days, not months: nobody chose to keep them, and
         * they are the one thing here that was explicitly left undecided.
         */
        app(ConversationPhotos::class)->pruneOlderThan((int) config('assistant.photo_days', 7));
        Storage::disk(config('assistant.reports_disk', 'local'))->delete($reports);

        $this->info($this->lineFor($counts, $cutoff, 'verwijderd'));

        return self::SUCCESS;
    }

    /** @param array{0: int, 1: int, 2: int} $counts */
    private function lineFor(array $counts, CarbonInterface $cutoff, string $verb): string
    {
        return $counts[0] . ' vragen, ' . $counts[1] . ' gespreksnotities en ' . $counts[2]
            . ' gemelde gesprekken ' . $verb . ' (ouder dan ' . $cutoff->toDateString() . ').';
    }

    /**
     * Reported conversations past their keeping, by the time the file was written.
     *
     * @return array<int, string>
     */
    private function reportsOlderThan(CarbonInterface $cutoff): array
    {
        $disk = Storage::disk(config('assistant.reports_disk', 'local'));
        $folder = trim((string) config('assistant.reports_path', 'assistant-reports'), '/');

        return collect($disk->files($folder))
            ->filter(fn (string $file) => $disk->lastModified($file) < $cutoff->timestamp)
            ->values()
            ->all();
    }
}

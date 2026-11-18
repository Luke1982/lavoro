<?php

namespace App\Jobs;

use App\Domain\Imports\CatalogPlan;
use App\Domain\Imports\CatalogWriter;
use App\Enums\CatalogImportStatus;
use App\Exceptions\Refusal;
use App\Models\CatalogImport;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Writes a confirmed spreadsheet import, a slice at a time.
 *
 * Each run works for at most WORK_SECONDS and then queues the next, so a list of
 * thousands of rows never runs into the worker's timeout -- past the queue's
 * retry_after a second worker would pick up the same job while the first is
 * still writing. Every slice is planned again just before it is written, so a
 * record made by an earlier slice, or by a run that died halfway, is found and
 * not made twice.
 *
 * One row that cannot be written is noted and skipped; the rest go on.
 */
class RunCatalogImportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const SLICE = 100;

    private const WORK_SECONDS = 20;

    private const MAX_FAILURES_KEPT = 50;

    public int $timeout = 80;

    public function __construct(public int $catalog_import_id) {}

    /**
     * Runs as the person who imported the list: the history of every changed
     * record reads its actor from the session, which a worker does not have.
     */
    public function handle(): void
    {
        $import = CatalogImport::find($this->catalog_import_id);

        if (!$import || !$import->status->isBusy()) {
            return;
        }

        Auth::setUser($import->user);

        try {
            $this->work($import);
        } finally {
            Auth::forgetUser();
        }
    }

    private function work(CatalogImport $import): void
    {
        $rows = json_decode((string) Storage::disk('local')->get($import->rowsPath()), true);

        if (!is_array($rows)) {
            throw new RuntimeException('De regels van import ' . $import->id . ' zijn niet meer te vinden.');
        }

        $import->update(['status' => CatalogImportStatus::running]);

        $plan = CatalogPlan::for($import);
        $writer = CatalogWriter::for($plan);
        $failures = $import->failures ?? [];
        $started = microtime(true);

        while ($import->processed < count($rows) && microtime(true) - $started < self::WORK_SECONDS) {
            $slice = array_slice($rows, $import->processed, self::SLICE);

            foreach ($plan->plan($slice) as $planned) {
                $this->apply($import, $writer, $planned, $failures);
            }

            $import->processed += count($slice);
            $import->failures = $failures;
            $import->save();
        }

        if ($import->processed < count($rows)) {
            self::dispatch($import->id);

            return;
        }

        $import->update(['status' => CatalogImportStatus::done]);
        $import->forgetFiles();
    }

    /** @param  array<int, array{line: int, message: string}>  $failures */
    private function apply(CatalogImport $import, CatalogWriter $writer, array $planned, array &$failures): void
    {
        if ($planned['action'] === 'unchanged') {
            $import->unchanged_count++;

            return;
        }

        if ($planned['action'] === 'skip') {
            $import->skipped_count++;

            return;
        }

        try {
            DB::transaction(fn () => $writer->write($planned));
        } catch (Throwable $e) {
            if (!$e instanceof Refusal) {
                report($e);
            }

            $import->skipped_count++;

            if (count($failures) < self::MAX_FAILURES_KEPT) {
                $failures[] = [
                    'line' => $planned['line'],
                    'message' => $e instanceof Refusal ? $e->getMessage() : 'Deze regel kon niet worden opgeslagen.',
                ];
            }

            return;
        }

        $planned['action'] === 'create' ? $import->created_count++ : $import->updated_count++;
    }

    public function failed(Throwable $e): void
    {
        $import = CatalogImport::find($this->catalog_import_id);

        if (!$import) {
            return;
        }

        $import->update([
            'status' => CatalogImportStatus::failed,
            'error' => 'Het importeren is na ' . $import->processed . ' van de ' . $import->total
                . ' regels afgebroken door een fout.',
        ]);
        $import->forgetFiles();
    }
}

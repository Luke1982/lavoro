<?php

namespace App\Jobs;

use App\Models\Image;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Deletes the photos of records that no longer exist.
 *
 * Nothing can be asked about a deleted record: the database cascades whole trees
 * away without a model event (a werkbon takes its checks, a product its machines).
 * What remains is the link, which still names the record it hung from. A link
 * whose record is gone is an orphan, and so is the photo it pointed at once no
 * other link does.
 *
 * Photos without any link at all go too, but only after a day, so an upload that
 * has created its image and not linked it yet is never caught in between. They
 * are what deleting a werkbon used to leave behind, and what a run that stopped
 * between a link and its photo leaves.
 */
class DeleteOrphanedImagesJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private int $deleted_links = 0;

    private int $deleted_images = 0;

    public function handle(): void
    {
        foreach (DB::table('imageables')->distinct()->pluck('imageable_type') as $type) {
            $this->deleteOrphanedLinksOf($type);
        }

        $this->deleteUnlinked(Image::where('created_at', '<', now()->subDay()));

        if ($this->deleted_links > 0 || $this->deleted_images > 0) {
            Log::info('Foto\'s van verwijderde records opgeruimd', [
                'links' => $this->deleted_links,
                'images' => $this->deleted_images,
            ]);
        }
    }

    /**
     * A type whose class is gone cannot say which table its records lived in, so
     * its links are left alone rather than guessed at.
     */
    private function deleteOrphanedLinksOf(string $type): void
    {
        if (!is_a($type, Model::class, true)) {
            return;
        }

        $record = new $type;

        DB::table('imageables')
            ->where('imageable_type', $type)
            ->whereNotExists(fn (Builder $query) => $query
                ->from($record->getTable())
                ->whereColumn($record->getQualifiedKeyName(), 'imageables.imageable_id'))
            ->chunkById(500, function (Collection $links) {
                DB::table('imageables')->whereIn('id', $links->pluck('id'))->delete();
                $this->deleted_links += $links->count();

                $this->deleteUnlinked(Image::whereIn('id', $links->pluck('image_id')));
            });
    }

    /** Through the model, so each photo takes its file along. */
    private function deleteUnlinked(EloquentBuilder $images): void
    {
        foreach ($images->whereNotIn('id', DB::table('imageables')->select('image_id'))->lazyById() as $image) {
            $image->delete();
            $this->deleted_images++;
        }
    }
}

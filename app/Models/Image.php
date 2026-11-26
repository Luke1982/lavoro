<?php

namespace App\Models;

use App\Models\Traits\HangsOffARecord;
use App\Models\Traits\Shareable;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Storage;

/**
 * An annotated photo keeps two files: `path` is the photo with the drawing burned in,
 * which every screen, PDF and mail shows, and `original_path` the photo as it was
 * taken, which the annotator opens again with `annotations` on top so a drawing can
 * still be moved or removed later. Neither travels along with the image: a list of
 * photos has no use for them, and freehand strokes are long.
 *
 * @property string $path
 * @property string|null $original_path
 * @property array|null $annotations
 */
class Image extends Model
{
    use HangsOffARecord, HasFactory, Shareable;

    /**
     * The records an image can be added to. An upload names its record by class,
     * and the controller builds that class: anything not on this list must never
     * get that far.
     */
    public const ATTACHABLE_TO = [
        Asset::class,
        Event::class,
        Material::class,
        Product::class,
        Project::class,
        ServiceCheckInstance::class,
        ServiceOrder::class,
        Ticket::class,
    ];

    protected $fillable = ['name', 'path', 'original_path', 'annotations', 'created_at', 'updated_at'];

    protected $hidden = ['original_path', 'annotations'];

    protected function casts(): array
    {
        return [
            'annotations' => 'array',
        ];
    }

    /**
     * An image takes its files along when it is deleted, and drops an old file when it
     * gets a new one. Uploads from before generated names could share a file, so only
     * once no image points at it any more; and only after the commit, so a deletion
     * that rolls back keeps its file.
     */
    protected static function booted(): void
    {
        static::deleted(function (Image $image) {
            $image->forgetFile($image->path);
            $image->forgetFile($image->original_path);
        });

        static::updated(function (Image $image) {
            foreach (['path', 'original_path'] as $column) {
                if ($image->wasChanged($column)) {
                    $image->forgetFile($image->getOriginal($column));
                }
            }
        });
    }

    /**
     * A photo shared from a project shows on every werkbon of that project
     * (ServiceOrder::sharedImages), so whoever may open one of those werkbonnen
     * may read it, project or not.
     */
    protected static function alsoVisibleTo(EloquentBuilder $query, User $user): void
    {
        $query->orWhereExists(fn (QueryBuilder $shares) => $shares->from('shares')
            ->whereColumn('shares.shareable_id', 'images.id')
            ->where('shares.shareable_type', (new self)->getMorphClass())
            ->where('shares.source_type', (new Project)->getMorphClass())
            ->whereIn(
                'shares.source_id',
                ServiceOrder::visibleTo($user)->whereNotNull('service_orders.project_id')->select('service_orders.project_id')
            ));
    }

    /**
     * Whether this person may add photos to this record by hand, as the upload on
     * its screen decides: on an appointment by its feedback, anywhere else with
     * the right to upload photos; and in both by mayPlaceOn.
     */
    public static function mayUploadOn(User $user, Model $record): bool
    {
        return ($record instanceof Event || $user->can('create', self::class))
            && self::mayPlaceOn($user, $record);
    }

    /** How a record photos hang off is named in a sentence: "Werkbon WB-0012", 'Keurpunt "Lekdicht"'. */
    public static function labelOf(Model $record): string
    {
        return $record instanceof ServiceCheckInstance
            ? 'Keurpunt "' . ($record->serviceCheck?->name ?? 'onbekend') . '"'
            : (string) $record->historyLabel();
    }

    /** Where photos are, by record and side: "de interne foto's van Werkbon WB-0012". */
    public static function placeOf(Model $record, bool $internal): string
    {
        return ($internal ? "de interne foto's van " : "de foto's van ") . self::labelOf($record);
    }

    /**
     * The photo as it was taken, without any drawing on it.
     */
    public function unannotatedPath(): string
    {
        return $this->original_path ?? $this->path;
    }

    private function forgetFile(?string $path): void
    {
        if ($path === null) {
            return;
        }

        $this->getConnection()->afterCommit(function () use ($path) {
            $still_used = static::where('path', $path)->orWhere('original_path', $path)->exists();

            if (!$still_used) {
                Storage::disk('public')->delete($path);
            }
        });
    }
}

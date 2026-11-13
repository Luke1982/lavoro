<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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
    use HasFactory;

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
     * The photo as it was taken, without any drawing on it.
     */
    public function unannotatedPath(): string
    {
        return $this->original_path ?? $this->path;
    }

    /**
     * The record the photo hangs from, read from the link itself rather than from
     * whatever record a client says it is looking at.
     */
    public function attachedRecord(): ?Model
    {
        $link = DB::table('imageables')->where('image_id', $this->id)->first();
        $model_class = $link?->imageable_type;

        if ($model_class === null || !is_subclass_of($model_class, Model::class)) {
            return null;
        }

        return $model_class::find($link->imageable_id);
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

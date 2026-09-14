<?php

namespace App\Models;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class Image extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'path', 'created_at', 'updated_at'];

    /**
     * An image takes its file along when it is deleted, and drops its old file when it
     * gets a new one. Uploads from before generated names could share a file, so only
     * once no image points at it any more; and only after the commit, so a deletion
     * that rolls back keeps its file.
     */
    protected static function booted(): void
    {
        static::deleted(fn (Image $image) => $image->forgetFile($image->path));

        static::updated(function (Image $image) {
            if ($image->wasChanged('path')) {
                $image->forgetFile($image->getOriginal('path'));
            }
        });
    }

    /**
     * Deletes the images hanging from these records, links and files included. Meant
     * for records the database cascades away without a model event; an image that is
     * still linked to something else stays.
     *
     * @param  class-string<Model>  $type
     * @param  array<int>|Builder  $ids
     */
    public static function deleteAttachedTo(string $type, array|Builder $ids): void
    {
        $links = DB::table('imageables')->where('imageable_type', $type)->whereIn('imageable_id', $ids);
        $image_ids = $links->pluck('image_id');
        $links->delete();

        static::whereIn('id', $image_ids)
            ->whereNotIn('id', DB::table('imageables')->select('image_id'))
            ->get()
            ->each->delete();
    }

    private function forgetFile(string $path): void
    {
        $this->getConnection()->afterCommit(function () use ($path) {
            if (!static::where('path', $path)->exists()) {
                Storage::disk('public')->delete($path);
            }
        });
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
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

    private function forgetFile(string $path): void
    {
        $this->getConnection()->afterCommit(function () use ($path) {
            if (!static::where('path', $path)->exists()) {
                Storage::disk('public')->delete($path);
            }
        });
    }
}

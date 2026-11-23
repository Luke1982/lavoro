<?php

namespace App\Actions\Images;

use App\Exceptions\Refusal;
use App\Models\Image;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Puts copies of photos among the photos of a record, on the side it names.
 *
 * A copy gets files of its own in the record's folder, so drawing on it, or
 * deleting it, leaves the photo it came from alone. A drawing comes along and can
 * still be moved on the copy: the photo as it was taken is copied with it.
 */
class CopyImagesAction
{
    public function __construct(private AttachImagesAction $attach) {}

    /**
     * @param  Model  $record  Anything with an images() morph relation.
     * @param  Collection<int, Image>  $images
     * @return array<int, Image>
     */
    public function execute(Model $record, Collection $images, bool $internal): array
    {
        $folder = AttachImagesAction::folderFor($record);

        return $this->attach->execute($record, $images->map(fn (Image $image) => [
            'name' => $image->name,
            'path' => $this->copyFile($image->path, $folder),
            'original_path' => $image->original_path === null ? null : $this->copyFile($image->original_path, $folder),
            'annotations' => $image->annotations,
        ])->values()->all(), $internal);
    }

    private function copyFile(string $path, string $folder): string
    {
        $disk = Storage::disk('public');
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $target = $folder . '/' . Str::random(40) . ($extension === '' ? '' : '.' . $extension);

        if (!$disk->exists($path) || !$disk->copy($path, $target)) {
            throw new Refusal('Een foto kon niet worden gekopieerd: het bestand is er niet meer.');
        }

        return $target;
    }
}

<?php

namespace Tests\Concerns;

use App\Models\Image;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

trait AttachesPhotos
{
    /**
     * A photo on the record, with its file on the public disk. Two photos given the
     * same path share one file, the way uploads from before generated names could.
     */
    protected function photoOn(Model $record, ?string $path = null, array $pivot = []): Image
    {
        $folder = 'uploaded/' . strtolower(class_basename($record)) . '/' . $record->getKey();
        $path ??= $folder . '/' . Str::random(40) . '.jpg';

        Storage::disk('public')->put($path, 'photo');

        $image = Image::create(['name' => 'Foto', 'path' => $path]);
        $record->images()->attach($image->id, $pivot);

        return $image;
    }
}

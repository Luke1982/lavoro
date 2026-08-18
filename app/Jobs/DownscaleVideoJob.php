<?php

namespace App\Jobs;

use App\Models\Document;
use App\Services\MediaDownscaler;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Storage;

/**
 * Zet een aangeleverde video om naar iets kleiners, buiten het verzoek om.
 *
 * Omzetten duurt minuten en de klant staat te wachten op een bevestiging, dus het
 * bestand wordt eerst opgeslagen zoals het binnenkwam en hier pas vervangen. Gaat
 * dat mis, dan blijft het origineel staan: dan is het groot, maar het is er.
 */
class DownscaleVideoJob implements ShouldQueue
{
    use Queueable;

    public function __construct(public int $document_id) {}

    public function handle(MediaDownscaler $downscaler): void
    {
        $document = Document::find($this->document_id);

        if (!$document) {
            return;
        }

        $disk = Storage::disk('public');
        $absolute = $disk->path($document->path);

        if (!is_file($absolute)) {
            return;
        }

        $result = $downscaler->video($absolute);

        if ($result === $absolute) {
            return;
        }

        $document->update([
            'path' => dirname($document->path) . '/' . basename($result),
            'name' => pathinfo($document->name, PATHINFO_FILENAME) . '.' . pathinfo($result, PATHINFO_EXTENSION),
            'size' => filesize($result) ?: $document->size,
        ]);
    }
}

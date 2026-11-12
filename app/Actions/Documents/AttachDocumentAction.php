<?php

namespace App\Actions\Documents;

use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Files a document on a record: the bytes on the tenant's disk, the row, the link.
 *
 * Shared by the upload on the screen and the assistant filing a document that
 * was dropped into the chat, so a document arrives the same way whichever door
 * it came through — same folder, same title, same place in the library.
 */
class AttachDocumentAction
{
    /**
     * @param  Model  $record  Anything with a documents() morph relation.
     */
    public function execute(
        Model $record,
        File $file,
        string $original_name,
        ?int $category_id,
        int $user_id,
        bool $internal = false,
    ): Document {
        $folder = 'uploaded/' . strtolower(class_basename($record)) . '/' . $record->getKey() . '/documents';

        $document = Document::create([
            'name' => $original_name,
            'path' => Storage::disk('public')->putFile($folder, $file),
            'size' => $file->getSize(),
            'title' => self::titleFrom($original_name),
            'document_category_id' => $category_id,
            'user_id' => $user_id,
        ]);

        $record->documents()->attach($document->id, ['internal' => $internal]);

        return $document;
    }

    private static function titleFrom(string $filename): string
    {
        return trim(preg_replace('/[\s_]+/', ' ', pathinfo($filename, PATHINFO_FILENAME)));
    }
}

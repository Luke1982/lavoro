<?php

namespace App\Actions\Documents;

use App\Domain\Signals\Attachments\DocumentAttached;
use App\Domain\Signals\Signals;
use App\Models\Document;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\File;

/**
 * Files a document on a record: the bytes on the tenant's disk, the row, the link.
 *
 * Shared by the upload on the screen and the assistant filing a document that
 * was dropped into the chat, so a document arrives the same way whichever door
 * it came through — same folder, same title, same place in the library. Whatever
 * one upload brings is told once, naming every file.
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
        return $this->attachAll($record, [[$file, $original_name]], $category_id, $user_id, $internal)[0];
    }

    /**
     * Every file of one upload, each under the name it had on the sender's device.
     *
     * @param  Model  $record  Anything with a documents() morph relation.
     * @param  array<int, UploadedFile>  $files
     * @return array<int, Document>
     */
    public function executeMany(
        Model $record,
        array $files,
        ?int $category_id,
        int $user_id,
        bool $internal = false,
    ): array {
        $named = array_map(fn (UploadedFile $file) => [$file, $file->getClientOriginalName()], array_values($files));

        return $this->attachAll($record, $named, $category_id, $user_id, $internal);
    }

    /**
     * @param  array<int, array{0: File, 1: string}>  $files  Each file with the name it came under.
     * @return array<int, Document>
     */
    private function attachAll(Model $record, array $files, ?int $category_id, int $user_id, bool $internal): array
    {
        $folder = 'uploaded/' . strtolower(class_basename($record)) . '/' . $record->getKey() . '/documents';
        $documents = [];

        foreach ($files as [$file, $original_name]) {
            $document = Document::create([
                'name' => $original_name,
                'path' => Storage::disk('public')->putFile($folder, $file),
                'size' => $file->getSize(),
                'title' => self::titleFrom($original_name),
                'document_category_id' => $category_id,
                'user_id' => $user_id,
            ]);

            $record->documents()->attach($document->id, ['internal' => $internal]);
            $documents[] = $document;
        }

        if ($documents !== []) {
            Signals::dispatch(new DocumentAttached($record, $documents, $internal));
        }

        return $documents;
    }

    private static function titleFrom(string $filename): string
    {
        return trim(preg_replace('/[\s_]+/', ' ', pathinfo($filename, PATHINFO_FILENAME)));
    }
}

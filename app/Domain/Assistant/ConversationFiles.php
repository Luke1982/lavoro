<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Contracts\Attachment;
use Illuminate\Support\Facades\Storage;

/**
 * The files that came with a conversation, parked for as long as it lasts.
 *
 * The same problem photos had: a datasheet belongs to the conversation, not to
 * the one message it arrived on. Ask "en wat is het opgenomen vermogen" a turn
 * later and the file is gone, so the assistant answers from memory about a
 * machine it can no longer look up — in the same voice it used when it could.
 *
 * Deliberately not the same class as the photos, and deliberately without a
 * keep(): a photo has an obvious home on a record and a pdf does not. Documents
 * belong in the document library with a category somebody chose, and inventing
 * one on their behalf is not this class's business. So these are borrowed for
 * the conversation and pruned afterwards, never quietly filed.
 */
class ConversationFiles
{
    /** What a media type is called on disk, so a parked file comes back as itself. */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'text/plain' => 'txt',
        'text/csv' => 'csv',
    ];

    /** @param array<int, Attachment> $attachments */
    public function stash(string $conversation, array $attachments): void
    {
        foreach ($attachments as $attachment) {
            if (!$attachment instanceof Attachment || !isset(self::EXTENSIONS[$attachment->media_type])) {
                continue;
            }

            Storage::disk('local')->put(
                $this->folder($conversation) . '/' . $this->safeName($attachment),
                base64_decode($attachment->base64),
            );
        }
    }

    /**
     * The parked files as attachments again, so a follow-up can read them.
     *
     * @return array<int, Attachment>
     */
    public function parked(string $conversation): array
    {
        $disk = Storage::disk('local');
        $types = array_flip(self::EXTENSIONS);

        return collect($disk->files($this->folder($conversation)))
            ->map(fn (string $file) => new Attachment(
                name: basename($file),
                media_type: $types[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/pdf',
                base64: base64_encode((string) $disk->get($file)),
            ))
            ->values()
            ->all();
    }

    public function has(string $conversation): bool
    {
        return Storage::disk('local')->files($this->folder($conversation)) !== [];
    }

    public function discard(string $conversation): void
    {
        Storage::disk('local')->deleteDirectory($this->folder($conversation));
    }

    /** Borrowed files, gone once the conversation they were borrowed for is cold. */
    public function pruneOlderThan(int $days): int
    {
        $disk = Storage::disk('local');
        $cutoff = now()->subDays($days)->timestamp;
        $pruned = 0;

        foreach ($disk->directories('assistant-files') as $folder) {
            $newest = collect($disk->files($folder))->map(fn (string $file) => $disk->lastModified($file))->max();

            if ($newest !== null && $newest < $cutoff) {
                $disk->deleteDirectory($folder);
                $pruned++;
            }
        }

        return $pruned;
    }

    /**
     * The name the file arrived under, stripped of anything that steers a path.
     * It is shown to the model as the title of the document, so it should stay
     * recognisable — "SRK71.pdf" tells it which machine it is holding.
     */
    private function safeName(Attachment $attachment): string
    {
        $extension = self::EXTENSIONS[$attachment->media_type];
        $stem = pathinfo(str_replace(['/', '\\'], '-', $attachment->name), PATHINFO_FILENAME);
        $stem = preg_replace('/[^A-Za-z0-9 _-]/', '', $stem) ?: 'bestand';

        return mb_substr(trim($stem), 0, 60) . '-' . uniqid() . '.' . $extension;
    }

    private function folder(string $conversation): string
    {
        return 'assistant-files/' . $conversation;
    }
}

<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Contracts\Attachment;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Collection;
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
 * the conversation and pruned afterwards, never quietly filed — the one way out
 * is save_document, on a click, in a category the person picked.
 *
 * Each file can carry a note: what the assistant took from it the first time it
 * read it. A signed offerte is worked through one click at a time, and sending
 * the whole pdf up again after every click cost pages of it to plan a mechanic
 * from two of its lines. Once noted, the steps after a click carry the note.
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

            $this->disk()->put(
                $this->folder($conversation) . '/' . $this->safeName($attachment),
                base64_decode($attachment->base64),
            );
        }
    }

    /**
     * The parked files as attachments again, so a follow-up can read them —
     * those nothing was noted from yet. A noted file stays behind, and the
     * model opens it again itself if the note falls short.
     *
     * @return array<int, Attachment>
     */
    public function unnoted(string $conversation): array
    {
        return $this->files($conversation)
            ->reject($this->isNoted(...))
            ->map($this->asAttachment(...))
            ->values()
            ->all();
    }

    /**
     * One parked file, by the name it arrived under — or, when only one file came
     * with the conversation, that one. Filing a document away is the one time a
     * borrowed file is kept, and only because somebody said so on a button.
     *
     * @return array{path: string, name: string}|null
     */
    public function named(string $conversation, ?string $name): ?array
    {
        $file = $this->find($conversation, $name);

        return $file === null ? null : ['path' => $this->disk()->path($file), 'name' => $this->arrivedAs($file)];
    }

    /** One parked file as an attachment again, for when its note falls short. */
    public function opened(string $conversation, ?string $name): ?Attachment
    {
        $file = $this->find($conversation, $name);

        return $file === null ? null : $this->asAttachment($file);
    }

    /**
     * Keeps what was taken from a file, so the steps after it can do without it.
     *
     * @return string|null The name of the file the note is about; null when there is no such file.
     */
    public function note(string $conversation, ?string $name, string $note): ?string
    {
        $file = $this->find($conversation, $name);

        if ($file === null) {
            return null;
        }

        $this->disk()->put($this->noteOf($file), $note);

        return $this->arrivedAs($file);
    }

    /** The notes, as the model reads them in place of the files; nothing when none were made. */
    public function reminder(string $conversation): string
    {
        $notes = $this->files($conversation)
            ->filter($this->isNoted(...))
            ->map(fn (string $file) => '[' . $this->arrivedAs($file) . "]\n"
                . $this->disk()->get($this->noteOf($file)));

        if ($notes->isEmpty()) {
            return '';
        }

        return 'Wat je eerder uit de meegestuurde bestanden hebt genoteerd. Het bestand zelf gaat niet '
            . "meer mee; mis je iets, lees het dan opnieuw met read_file in plaats van het in te vullen.\n"
            . $notes->implode("\n\n");
    }

    /** What the model is told when it named a file this conversation does not have. */
    public function whichOne(string $conversation): string
    {
        $names = $this->files($conversation)->map($this->arrivedAs(...));

        return $names->isEmpty()
            ? 'Er is in dit gesprek geen bestand meegestuurd, of het is al opgeruimd.'
            : 'Welk bestand? Meegestuurd zijn: ' . $names->implode(', ') . '. Geef file_name mee.';
    }

    public function has(string $conversation): bool
    {
        return $this->files($conversation)->isNotEmpty();
    }

    public function discard(string $conversation): void
    {
        $this->disk()->deleteDirectory($this->folder($conversation));
    }

    /** Borrowed files, gone once the conversation they were borrowed for is cold. */
    public function pruneOlderThan(int $days): int
    {
        $disk = $this->disk();
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
     * Nothing for no conversation, rather than whatever lies loose at the top of the folder.
     *
     * @return Collection<int, string>
     */
    private function files(string $conversation): Collection
    {
        return $conversation === '' ? collect() : collect($this->disk()->files($this->folder($conversation)));
    }

    /**
     * Matched on the name after the same clean-up it had on the way in, so the
     * model can hand back "B.V. Offerte.pdf" for the file parked as "BV Offerte".
     */
    private function find(string $conversation, ?string $name): ?string
    {
        $files = $this->files($conversation);

        if (blank($name)) {
            return $files->count() === 1 ? $files->first() : null;
        }

        return $files->first(fn (string $file) => $this->arrivedAs($file) === $this->cleaned($name));
    }

    private function asAttachment(string $file): Attachment
    {
        return new Attachment(
            name: $this->arrivedAs($file),
            media_type: array_flip(self::EXTENSIONS)[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/pdf',
            base64: base64_encode((string) $this->disk()->get($file)),
        );
    }

    /** Beside the file in a folder of its own, so it is never taken for a parked file and goes with them. */
    private function noteOf(string $file): string
    {
        return dirname($file) . '/notes/' . basename($file) . '.txt';
    }

    private function isNoted(string $file): bool
    {
        return $this->disk()->exists($this->noteOf($file));
    }

    /** The parked name without the suffix that kept two uploads of one name apart. */
    private function arrivedAs(string $file): string
    {
        return preg_replace('/-[0-9a-f]{13}(\.[a-z]+)$/', '$1', basename($file));
    }

    /**
     * The name the file arrived under, stripped of anything that steers a path.
     * It is shown to the model as the title of the document, so it should stay
     * recognisable — "SRK71.pdf" tells it which machine it is holding.
     */
    private function safeName(Attachment $attachment): string
    {
        $extension = self::EXTENSIONS[$attachment->media_type];

        return pathinfo($this->cleaned($attachment->name), PATHINFO_FILENAME) . '-' . uniqid() . '.' . $extension;
    }

    private function cleaned(string $name): string
    {
        $name = str_replace(['/', '\\'], '-', $name);
        $stem = preg_replace('/[^A-Za-z0-9 _-]/', '', pathinfo($name, PATHINFO_FILENAME)) ?: 'bestand';
        $extension = pathinfo($name, PATHINFO_EXTENSION);

        return mb_substr(trim($stem), 0, 60) . ($extension === '' ? '' : '.' . mb_strtolower($extension));
    }

    private function disk(): Filesystem
    {
        return Storage::disk('local');
    }

    private function folder(string $conversation): string
    {
        return 'assistant-files/' . $conversation;
    }
}

<?php

namespace App\Domain\Assistant\Transcription;

/**
 * Turns a spoken recording into text, so somebody can tell the assistant what
 * they want instead of typing it. Which supplier listens is configuration; the
 * assistant only ever sees the words.
 */
interface Transcriber
{
    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self;

    /** @throws TranscriptionFailed */
    public function transcribe(string $path, string $mime_type, string $file_name): Transcription;
}

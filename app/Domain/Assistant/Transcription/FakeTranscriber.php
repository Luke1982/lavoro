<?php

namespace App\Domain\Assistant\Transcription;

/** Writes out every recording as the same sentence, without sending anything anywhere. */
class FakeTranscriber implements Transcriber
{
    public function __construct(private readonly string $text) {}

    public static function fromConfig(array $config): self
    {
        return new self($config['text'] ?? 'Elke airco kost vijftig euro per jaar.');
    }

    public function transcribe(string $path, string $mime_type, string $file_name): Transcription
    {
        return new Transcription($this->text, RecordingLength::of($path), 'fake');
    }
}

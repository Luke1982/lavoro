<?php

namespace App\Domain\Assistant\Transcription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * A supplier that listens over the internet. Sending the recording, and what
 * can go wrong on the way, is the same for every one of them: a supplier only
 * says where it listens, how it is let in, what it is asked, how long it heard
 * and what its use is booked under.
 */
abstract class HttpTranscriber implements Transcriber
{
    public function __construct(
        protected readonly ?string $key,
        protected readonly string $model,
    ) {}

    /** The name the log and the person's administrator know it by. */
    abstract protected function supplier(): string;

    abstract protected function url(): string;

    /** @return array<string, string> */
    abstract protected function headers(): array;

    /** @return array<string, string> */
    abstract protected function fields(): array;

    abstract protected function seconds(Response $response, string $path): float;

    abstract protected function bookedAs(): string;

    public function transcribe(string $path, string $mime_type, string $file_name): Transcription
    {
        if (blank($this->key)) {
            throw TranscriptionFailed::missingKey($this->supplier());
        }

        try {
            $response = Http::withHeaders($this->headers())
                ->timeout(120)
                ->attach('file', fopen($path, 'r'), $file_name, ['Content-Type' => $mime_type])
                ->post($this->url(), $this->fields());
        } catch (ConnectionException $exception) {
            throw TranscriptionFailed::unreachable($this->supplier(), $exception);
        }

        if ($response->failed()) {
            throw TranscriptionFailed::from($response, $this->supplier());
        }

        return new Transcription(
            text: trim((string) $response->json('text')),
            seconds: $this->seconds($response, $path),
            model: $this->bookedAs(),
        );
    }
}

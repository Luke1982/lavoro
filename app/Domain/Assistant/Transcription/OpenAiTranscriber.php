<?php

namespace App\Domain\Assistant\Transcription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * OpenAI's transcription. It does not say how long the recording was, so the
 * length is measured from the file itself.
 */
class OpenAiTranscriber implements Transcriber
{
    public function __construct(
        private readonly ?string $key,
        private readonly string $model,
    ) {}

    public static function fromConfig(array $config): self
    {
        return new self($config['key'] ?? null, $config['model'] ?? 'gpt-4o-mini-transcribe');
    }

    public function transcribe(string $path, string $mime_type, string $file_name): Transcription
    {
        if (blank($this->key)) {
            throw TranscriptionFailed::missingKey('OpenAI');
        }

        try {
            $response = Http::withToken($this->key)
                ->timeout(120)
                ->attach('file', fopen($path, 'r'), $file_name, ['Content-Type' => $mime_type])
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $this->model,
                    'language' => 'nl',
                    'response_format' => 'json',
                ]);
        } catch (ConnectionException $exception) {
            throw TranscriptionFailed::unreachable('OpenAI', $exception);
        }

        if ($response->failed()) {
            throw TranscriptionFailed::from($response, 'OpenAI');
        }

        return new Transcription(
            text: trim((string) $response->json('text')),
            seconds: RecordingLength::of($path),
            model: 'openai:' . $this->model,
        );
    }
}

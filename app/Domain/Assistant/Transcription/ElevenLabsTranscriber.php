<?php

namespace App\Domain\Assistant\Transcription;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * ElevenLabs Scribe. Hears Dutch as Dutch, and says where each word ends, which
 * is what the recording is billed by.
 */
class ElevenLabsTranscriber implements Transcriber
{
    public function __construct(
        private readonly ?string $key,
        private readonly string $model,
    ) {}

    public static function fromConfig(array $config): self
    {
        return new self($config['key'] ?? null, $config['model'] ?? 'scribe_v1');
    }

    public function transcribe(string $path, string $mime_type, string $file_name): Transcription
    {
        if (blank($this->key)) {
            throw TranscriptionFailed::missingKey('ElevenLabs');
        }

        try {
            $response = Http::withHeaders(['xi-api-key' => $this->key])
                ->timeout(120)
                ->attach('file', fopen($path, 'r'), $file_name, ['Content-Type' => $mime_type])
                ->post('https://api.elevenlabs.io/v1/speech-to-text', [
                    'model_id' => $this->model,
                    'language_code' => 'nld',
                    'tag_audio_events' => 'false',
                ]);
        } catch (ConnectionException $exception) {
            throw TranscriptionFailed::unreachable('ElevenLabs', $exception);
        }

        if ($response->failed()) {
            throw TranscriptionFailed::from($response, 'ElevenLabs');
        }

        $ends = array_column((array) $response->json('words'), 'end');

        return new Transcription(
            text: trim((string) $response->json('text')),
            seconds: $ends === [] ? RecordingLength::of($path) : (float) max($ends),
            model: 'elevenlabs:' . $this->model,
        );
    }
}

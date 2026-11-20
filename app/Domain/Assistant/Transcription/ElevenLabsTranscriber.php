<?php

namespace App\Domain\Assistant\Transcription;

use Illuminate\Http\Client\Response;

/**
 * ElevenLabs Scribe. Hears Dutch as Dutch, and says where each word ends, which
 * is what the recording is billed by.
 */
class ElevenLabsTranscriber extends HttpTranscriber
{
    public static function fromConfig(array $config): self
    {
        return new self($config['key'] ?? null, $config['model'] ?? 'scribe_v1');
    }

    protected function supplier(): string
    {
        return 'ElevenLabs';
    }

    protected function url(): string
    {
        return 'https://api.elevenlabs.io/v1/speech-to-text';
    }

    protected function headers(): array
    {
        return ['xi-api-key' => $this->key];
    }

    protected function fields(): array
    {
        return [
            'model_id' => $this->model,
            'language_code' => 'nld',
            'tag_audio_events' => 'false',
        ];
    }

    protected function seconds(Response $response, string $path): float
    {
        $ends = array_column((array) $response->json('words'), 'end');

        return $ends === [] ? RecordingLength::of($path) : (float) max($ends);
    }

    protected function bookedAs(): string
    {
        return 'elevenlabs:' . $this->model;
    }
}

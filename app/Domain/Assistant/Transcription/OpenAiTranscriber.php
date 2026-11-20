<?php

namespace App\Domain\Assistant\Transcription;

use Illuminate\Http\Client\Response;

/**
 * OpenAI's transcription. It does not say how long the recording was, so the
 * length is measured from the file itself.
 */
class OpenAiTranscriber extends HttpTranscriber
{
    public static function fromConfig(array $config): self
    {
        return new self($config['key'] ?? null, $config['model'] ?? 'gpt-4o-mini-transcribe');
    }

    protected function supplier(): string
    {
        return 'OpenAI';
    }

    protected function url(): string
    {
        return 'https://api.openai.com/v1/audio/transcriptions';
    }

    protected function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->key];
    }

    protected function fields(): array
    {
        return [
            'model' => $this->model,
            'language' => 'nl',
            'response_format' => 'json',
        ];
    }

    protected function seconds(Response $response, string $path): float
    {
        return RecordingLength::of($path);
    }

    protected function bookedAs(): string
    {
        return 'openai:' . $this->model;
    }
}

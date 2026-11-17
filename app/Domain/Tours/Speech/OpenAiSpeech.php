<?php

namespace App\Domain\Tours\Speech;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class OpenAiSpeech implements SpeechDriver
{
    public function __construct(
        private readonly ?string $key,
        private readonly string $voice,
        private readonly string $model,
        private readonly ?string $instructions,
    ) {}

    public static function fromConfig(array $config): self
    {
        return new self(
            $config['key'] ?? null,
            $config['voice'] ?? 'nova',
            $config['model'] ?? 'gpt-4o-mini-tts',
            $config['instructions'] ?? null,
        );
    }

    public function signature(): string
    {
        return 'openai:' . $this->voice . ':' . $this->model . ':' . substr(sha1((string) $this->instructions), 0, 8);
    }

    public function speak(string $text): string
    {
        if (blank($this->key)) {
            throw new RuntimeException('OpenAI heeft een sleutel nodig: zet TOUR_OPENAI_API_KEY (of OPENAI_API_KEY) in .env.');
        }

        $payload = [
            'model' => $this->model,
            'voice' => $this->voice,
            'input' => $text,
            'response_format' => 'mp3',
        ];

        if (filled($this->instructions)) {
            $payload['instructions'] = $this->instructions;
        }

        $response = Http::withToken($this->key)
            ->timeout(90)
            ->retry(2, 2000, throw: false)
            ->post('https://api.openai.com/v1/audio/speech', $payload);

        if ($response->failed()) {
            throw new RuntimeException('OpenAI gaf ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        return $response->body();
    }
}

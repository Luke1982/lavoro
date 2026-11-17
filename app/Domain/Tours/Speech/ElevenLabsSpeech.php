<?php

namespace App\Domain\Tours\Speech;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ElevenLabsSpeech implements SpeechDriver
{
    public function __construct(
        private readonly ?string $key,
        private readonly ?string $voice,
        private readonly string $model,
    ) {}

    public static function fromConfig(array $config): self
    {
        return new self($config['key'] ?? null, $config['voice'] ?? null, $config['model'] ?? 'eleven_multilingual_v2');
    }

    public function signature(): string
    {
        return 'elevenlabs:' . $this->voice . ':' . $this->model;
    }

    public function speak(string $text): string
    {
        if (blank($this->key) || blank($this->voice)) {
            throw new RuntimeException('ElevenLabs heeft een sleutel en een stem nodig: zet ELEVENLABS_API_KEY en '
                . 'ELEVENLABS_VOICE_ID in .env.');
        }

        $response = Http::withHeaders(['xi-api-key' => $this->key, 'Accept' => 'audio/mpeg'])
            ->timeout(90)
            ->retry(2, 2000, throw: false)
            ->post('https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($this->voice) . '?output_format=mp3_44100_128', [
                'text' => $text,
                'model_id' => $this->model,
                'language_code' => 'nl',
                'voice_settings' => ['stability' => 0.55, 'similarity_boost' => 0.8, 'style' => 0.1],
            ]);

        if ($response->failed()) {
            throw new RuntimeException('ElevenLabs gaf ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        return $response->body();
    }
}

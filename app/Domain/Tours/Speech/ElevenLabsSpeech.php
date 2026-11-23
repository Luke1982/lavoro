<?php

namespace App\Domain\Tours\Speech;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class ElevenLabsSpeech implements SpeechDriver
{
    /** Style at 0: anything above it makes a cloned voice less steady. */
    private const SETTINGS = ['stability' => 0.55, 'similarity_boost' => 0.8, 'style' => 0.0];

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
        return 'elevenlabs:' . $this->voice . ':' . $this->model . ':' . substr(sha1(json_encode(self::SETTINGS)), 0, 8);
    }

    /**
     * Asked with timestamps: the voice ends a line by drawing breath for a sentence
     * that never comes, and only the supplier knows where the last word stops.
     */
    public function speak(string $text, ?string $previous = null, ?string $next = null): SpokenLine
    {
        if (blank($this->key) || blank($this->voice)) {
            throw new RuntimeException('ElevenLabs heeft een sleutel en een stem nodig: zet ELEVENLABS_API_KEY en '
                . 'ELEVENLABS_VOICE_ID in .env.');
        }

        $response = Http::withHeaders(['xi-api-key' => $this->key])
            ->timeout(90)
            ->retry(2, 2000, throw: false)
            ->post('https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode($this->voice)
                . '/with-timestamps?output_format=mp3_44100_128', [
                    'text' => $text,
                    'model_id' => $this->model,
                    'language_code' => 'nl',
                    'voice_settings' => self::SETTINGS,
                    'previous_text' => $previous,
                    'next_text' => $next,
                ]);

        $audio = base64_decode((string) $response->json('audio_base64'), true);
        if ($response->failed() || !$audio) {
            throw new RuntimeException('ElevenLabs gaf ' . $response->status() . ': ' . mb_substr($response->body(), 0, 300));
        }

        return new SpokenLine($audio, $this->lastLetterEnd((array) $response->json('alignment')));
    }

    /** @param  array{characters?: array<int, string>, character_end_times_seconds?: array<int, float>}  $alignment */
    private function lastLetterEnd(array $alignment): ?float
    {
        $is_letter = fn (string $character) => preg_match('/[\p{L}\p{N}]/u', $character) === 1;
        $last = array_key_last(array_filter($alignment['characters'] ?? [], $is_letter));
        if ($last === null) {
            return null;
        }

        $end = $alignment['character_end_times_seconds'][$last] ?? null;

        return is_numeric($end) ? (float) $end : null;
    }
}

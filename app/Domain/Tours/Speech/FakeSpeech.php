<?php

namespace App\Domain\Tours\Speech;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * A soft tone as long as the line would take to say. For trying the command and
 * the player without a key, and without sending anything anywhere.
 */
class FakeSpeech implements SpeechDriver
{
    public function __construct(private readonly string $ffmpeg) {}

    public static function fromConfig(array $config): self
    {
        return new self((string) config('tours.ffmpeg'));
    }

    public function signature(): string
    {
        return 'fake:tone';
    }

    public function speak(string $text): SpokenLine
    {
        $words = count(preg_split('/\s+/', trim($text), -1, PREG_SPLIT_NO_EMPTY));
        $seconds = number_format(max(1.5, $words / 2.4), 2, '.', '');
        $base = (string) tempnam(sys_get_temp_dir(), 'tour-fake-');
        $path = $base . '.mp3';

        $result = Process::run([
            $this->ffmpeg, '-y', '-loglevel', 'error',
            '-f', 'lavfi', '-i', 'sine=frequency=330:duration=' . $seconds,
            '-af', 'volume=0.08', '-ac', '1', '-ar', '44100', '-b:a', '64k', $path,
        ]);

        if ($result->failed() || !is_file($path)) {
            @unlink($base);

            throw new RuntimeException('ffmpeg kon geen proeftoon maken: ' . trim($result->errorOutput()));
        }

        $audio = (string) file_get_contents($path);
        @unlink($path);
        @unlink($base);

        return new SpokenLine($audio);
    }
}

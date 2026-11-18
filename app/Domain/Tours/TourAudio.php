<?php

namespace App\Domain\Tours;

use App\Domain\Tours\Speech\SpeechDriver;
use Closure;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Speaks a chapter of a rondleiding into one file the screen can play.
 *
 * Every step is spoken on its own and kept under the hash of voice and words, so
 * a rewritten sentence costs one new clip and not a whole chapter. The clips are
 * then joined, with a breath of silence after each, and the second each step
 * starts and ends is written beside the file together with the words that were
 * spoken. The player compares those words with the storyboard and runs the step
 * in silence, with the text on screen, the moment they differ.
 */
class TourAudio
{
    public function __construct(
        private readonly SpeechDriver $driver,
        private readonly string $clips_root,
        private readonly string $output_root,
        private readonly string $ffmpeg,
        private readonly string $ffprobe,
        private readonly float $gap_seconds,
    ) {}

    /**
     * @param  array<string, mixed>  $chapter
     * @param  Closure(string): void  $report
     * @return array{spoken: int, kept: int, rebuilt: bool}
     */
    public function speakChapter(string $tour, array $chapter, bool $force, Closure $report): array
    {
        $clip_folder = $this->clips_root . '/' . $tour . '/' . $chapter['id'];
        $steps = [];
        $spoken = 0;

        foreach ($chapter['steps'] as $index => $step) {
            $text = trim((string) $step['say']);
            $hash = sha1($this->driver->signature() . "\n" . $text);
            $path = $clip_folder . '/' . $hash . '.mp3';

            if ($force || !is_file($path)) {
                File::ensureDirectoryExists($clip_folder);
                File::put($path, $this->driver->speak($text));
                $spoken++;
                $report('  stap ' . ($index + 1) . ' ingesproken');
            }

            $steps[] = ['text' => $text, 'hash' => $hash, 'path' => $path];
        }

        $output_folder = $this->output_root . '/' . $tour;
        $audio_name = $chapter['id'] . '.mp3';
        $cue_path = $output_folder . '/' . $chapter['id'] . '.json';

        if (!$force && $spoken === 0 && $this->isCurrent($cue_path, $output_folder . '/' . $audio_name, $chapter, $steps)) {
            return ['spoken' => 0, 'kept' => count($steps), 'rebuilt' => false];
        }

        File::ensureDirectoryExists($output_folder);
        $work = sys_get_temp_dir() . '/tour-audio-' . bin2hex(random_bytes(6));
        File::ensureDirectoryExists($work);

        try {
            $cues = [];
            $pieces = [];
            $cursor = 0.0;
            foreach ($steps as $index => $step) {
                $piece = $work . '/' . $index . '.wav';
                $this->decode($step['path'], $piece);
                $duration = $this->duration($piece);
                $cues[] = [
                    'text' => $step['text'],
                    'hash' => $step['hash'],
                    'start' => round($cursor, 3),
                    'end' => round($cursor + $duration, 3),
                ];
                $cursor += $duration + $this->gap_seconds;
                $pieces[] = $piece;
            }

            $this->join($pieces, $output_folder . '/' . $audio_name);
        } finally {
            File::deleteDirectory($work);
        }

        File::put($cue_path, json_encode([
            'tour' => $tour,
            'chapter' => $chapter['id'],
            'version' => $chapter['version'],
            'voice' => $this->driver->signature(),
            'audio' => $audio_name,
            'gap' => $this->gap_seconds,
            'steps' => $cues,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

        return ['spoken' => $spoken, 'kept' => count($steps) - $spoken, 'rebuilt' => true];
    }

    /**
     * Whether the chapter file was built from these very lines, for this version
     * of the chapter, with this much silence between them.
     *
     * @param  array<string, mixed>  $chapter
     * @param  array<int, array{text: string, hash: string, path: string}>  $steps
     */
    private function isCurrent(string $cue_path, string $audio_path, array $chapter, array $steps): bool
    {
        if (!is_file($cue_path) || !is_file($audio_path)) {
            return false;
        }

        $cue = json_decode((string) file_get_contents($cue_path), true);

        return is_array($cue)
            && ($cue['version'] ?? null) === $chapter['version']
            && (float) ($cue['gap'] ?? -1) === $this->gap_seconds
            && array_column($cue['steps'] ?? [], 'hash') === array_column($steps, 'hash');
    }

    /**
     * Decoded to plain samples first: an MP3 carries encoder padding that makes
     * its reported length longer than what is heard, and over a chapter that
     * drift would start a step in the middle of its first word.
     */
    private function decode(string $source, string $target): void
    {
        $result = Process::run([
            $this->ffmpeg, '-y', '-loglevel', 'error', '-i', $source,
            '-ac', '1', '-ar', '44100', '-c:a', 'pcm_s16le', $target,
        ]);

        if ($result->failed() || !is_file($target)) {
            throw new RuntimeException('ffmpeg kon ' . $source . ' niet lezen: ' . trim($result->errorOutput()));
        }
    }

    private function duration(string $path): float
    {
        $result = Process::run([
            $this->ffprobe, '-v', 'error', '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1', $path,
        ]);

        $seconds = (float) trim($result->output());
        if ($result->failed() || $seconds <= 0) {
            throw new RuntimeException('ffprobe kon de lengte van ' . $path . ' niet lezen: ' . trim($result->errorOutput()));
        }

        return $seconds;
    }

    /**
     * @param  array<int, string>  $paths
     */
    private function join(array $paths, string $target): void
    {
        $inputs = [];
        $filters = [];
        foreach ($paths as $index => $path) {
            array_push($inputs, '-i', $path);
            $filters[] = '[' . $index . ':a]apad=pad_dur=' . $this->gap_seconds . '[a' . $index . ']';
        }
        $labels = implode('', array_map(fn (int $index) => '[a' . $index . ']', array_keys($paths)));
        $filter = implode(';', $filters) . ';' . $labels . 'concat=n=' . count($paths) . ':v=0:a=1[out]';

        $result = Process::timeout(300)->run([
            $this->ffmpeg, '-y', '-loglevel', 'error', ...$inputs,
            '-filter_complex', $filter, '-map', '[out]', '-ac', '1', '-ar', '44100', '-b:a', '96k', $target,
        ]);

        if ($result->failed() || !is_file($target)) {
            throw new RuntimeException('ffmpeg kon de stukken niet samenvoegen: ' . trim($result->errorOutput()));
        }
    }
}

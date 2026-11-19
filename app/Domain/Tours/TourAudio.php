<?php

namespace App\Domain\Tours;

use App\Domain\Tours\Speech\SpeechDriver;
use App\Domain\Tours\Speech\SpokenLine;
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
    /**
     * After its last word a voice may draw breath. A breath is a sound at least
     * BREATH_RISE decibel louder than the quiet before it, louder than
     * BREATH_FLOOR, for BREATH_FRAMES hundredths of a second: a word's own last
     * click is shorter. It is looked for in the BREATH_WINDOW seconds after the
     * word, and the cut fades out over FADE seconds.
     */
    private const BREATH_WINDOW = 0.3;

    private const BREATH_RISE = 12;

    private const BREATH_FLOOR = -65;

    private const BREATH_FRAMES = 3;

    private const FADE = 0.03;

    private const FRAME_SECONDS = 0.01;

    private const ANALYSIS_RATE = 16000;

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
                $this->store($this->driver->speak($text), $path);
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
     * Keeps the line without the breath a voice may draw after its last word, for
     * a sentence that does not come. The cut goes in the quietest moment just
     * after that word, so the word keeps its own fading tail.
     */
    private function store(SpokenLine $line, string $path): void
    {
        File::put($path, $line->audio);
        $cut = $line->words_end === null ? null : $this->endOfSpeech($path, $line->words_end);
        if ($cut === null) {
            return;
        }

        $whole = $path . '.whole.mp3';
        File::move($path, $whole);

        try {
            $result = Process::run([
                $this->ffmpeg, '-y', '-loglevel', 'error', '-i', $whole, '-t', $this->seconds($cut),
                '-af', 'afade=t=out:st=' . $this->seconds(max(0, $cut - self::FADE)) . ':d=' . self::FADE,
                '-ac', '1', '-ar', '44100', '-b:a', '128k', $path,
            ]);

            if ($result->failed() || !is_file($path)) {
                File::delete($path);

                throw new RuntimeException('ffmpeg kon ' . $path . ' niet inkorten: ' . trim($result->errorOutput()));
            }
        } finally {
            File::delete($whole);
        }
    }

    /** The quietest moment after the last word and before any breath. */
    private function endOfSpeech(string $path, float $words_end): ?float
    {
        $levels = $this->levelsAfter($path, $words_end);
        if (!$levels) {
            return null;
        }

        $quietest = 0;
        $loud = 0;
        foreach ($levels as $index => $level) {
            if ($level < $levels[$quietest]) {
                $quietest = $index;
                $loud = 0;

                continue;
            }
            $loud = $level > max($levels[$quietest] + self::BREATH_RISE, self::BREATH_FLOOR) ? $loud + 1 : 0;
            if ($loud === self::BREATH_FRAMES) {
                break;
            }
        }

        return $words_end + ($quietest + 0.5) * self::FRAME_SECONDS;
    }

    /**
     * The loudness of every FRAME_SECONDS in the BREATH_WINDOW from `$from`. The
     * whole line is decoded: an MP3 read from a point halfway starts with a
     * stretch of silence that is not in the line.
     *
     * @return array<int, float>
     */
    private function levelsAfter(string $path, float $from): array
    {
        $result = Process::run([
            $this->ffmpeg, '-loglevel', 'error', '-i', $path,
            '-ac', '1', '-ar', (string) self::ANALYSIS_RATE, '-f', 's16le', '-',
        ]);
        $samples = $result->successful() ? array_values(unpack('s*', $result->output()) ?: []) : [];
        $window = array_slice($samples, (int) ($from * self::ANALYSIS_RATE), (int) (self::BREATH_WINDOW * self::ANALYSIS_RATE));

        return array_map(fn (array $frame) => $this->decibels($frame), array_chunk($window, (int) (self::ANALYSIS_RATE * self::FRAME_SECONDS)));
    }

    /** @param  array<int, int>  $samples */
    private function decibels(array $samples): float
    {
        $power = array_sum(array_map(fn (int $sample) => $sample * $sample, $samples)) / count($samples);

        return 10 * log10(max($power, 1) / 32768 ** 2);
    }

    private function seconds(float $seconds): string
    {
        return number_format($seconds, 3, '.', '');
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

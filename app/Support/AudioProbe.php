<?php

namespace App\Support;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Asks ffprobe how long a sound file lasts. The rondleidingen time their steps
 * with it and the assistant books what writing out a recording costs by it, so
 * there is one ffprobe for both: `tours.ffprobe`, which tenancy:doctor checks.
 */
final class AudioProbe
{
    public function __construct(public readonly string $ffprobe) {}

    public static function fromConfig(): self
    {
        return new self((string) config('tours.ffprobe'));
    }

    /**
     * The length the file's header gives.
     *
     * @throws RuntimeException when ffprobe cannot tell
     */
    public function duration(string $path): float
    {
        return $this->seconds($path, ['-show_entries', 'format=duration'], last: false);
    }

    /**
     * When the last audio packet starts: the length of a file whose header gives
     * none, as with a recording made in the browser.
     *
     * @throws RuntimeException when ffprobe cannot tell
     */
    public function lastPacketTime(string $path): float
    {
        return $this->seconds($path, ['-select_streams', 'a:0', '-show_entries', 'packet=pts_time'], last: true);
    }

    /** @param array<int, string> $entries */
    private function seconds(string $path, array $entries, bool $last): float
    {
        $result = Process::timeout(30)->run([
            $this->ffprobe, '-v', 'error', ...$entries, '-of', 'csv=p=0', $path,
        ]);

        $values = array_filter(array_map('trim', explode("\n", $result->output())), 'is_numeric');
        $seconds = (float) ($last ? end($values) : reset($values));

        if ($result->failed() || $seconds <= 0) {
            throw new RuntimeException('ffprobe kon de lengte van ' . $path . ' niet lezen: ' . trim($result->errorOutput()));
        }

        return $seconds;
    }
}

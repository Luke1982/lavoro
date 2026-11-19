<?php

namespace App\Domain\Assistant\Transcription;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;

/**
 * How long a recording lasts, which is what writing it out is billed by.
 *
 * A recording made in the browser has no length in its header, so then the
 * last audio packet's time is the length. A recording that cannot be measured
 * is booked at nothing, and says so in the log: an allowance that quietly
 * under-reports is as wrong as one that over-reports.
 */
final class RecordingLength
{
    public static function of(string $path): float
    {
        $seconds = self::probe($path, ['-show_entries', 'format=duration'])
            ?: self::probe($path, ['-select_streams', 'a:0', '-show_entries', 'packet=pts_time'], last: true);

        if ($seconds <= 0) {
            Log::warning('De lengte van een opname kon niet worden gemeten; uitschrijven is als gratis geboekt', [
                'ffprobe' => config('tours.ffprobe'),
            ]);
        }

        return $seconds;
    }

    /** @param array<int, string> $entries */
    private static function probe(string $path, array $entries, bool $last = false): float
    {
        $result = Process::timeout(30)->run([
            (string) config('tours.ffprobe'), '-v', 'error', ...$entries, '-of', 'csv=p=0', $path,
        ]);

        if ($result->failed()) {
            return 0.0;
        }

        $values = array_filter(array_map('trim', explode("\n", $result->output())), 'is_numeric');
        $value = $last ? end($values) : reset($values);

        return $value === false ? 0.0 : max(0.0, (float) $value);
    }
}

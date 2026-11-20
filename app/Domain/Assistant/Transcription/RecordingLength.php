<?php

namespace App\Domain\Assistant\Transcription;

use App\Support\AudioProbe;
use Illuminate\Support\Facades\Log;

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
        $probe = AudioProbe::fromConfig();

        $seconds = rescue(fn () => $probe->duration($path), 0.0, report: false)
            ?: rescue(fn () => $probe->lastPacketTime($path), 0.0, report: false);

        if ($seconds <= 0) {
            Log::warning('De lengte van een opname kon niet worden gemeten; uitschrijven is als gratis geboekt', [
                'ffprobe' => $probe->ffprobe,
            ]);
        }

        return $seconds;
    }
}

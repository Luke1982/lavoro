<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Laat een draaiende worker van zich horen.
 *
 * Zonder dit is er geen manier om te zien of een worker leeft: een lege
 * wachtrij ziet er hetzelfde uit als een worker die er niet is, en dat is
 * precies het geval waarin er stilletjes niets meer gebeurt.
 *
 * Queue::looping vuurt bij elke ronde van de worker, ook als er geen werk is.
 * Dat is dus een hartslag en geen teller van verwerkte jobs.
 */
final class WorkerHeartbeat
{
    /** Hoe vaak er hoogstens geschreven wordt. De lus draait elke seconde. */
    private const EVERY_SECONDS = 60;

    public const STALE_AFTER_MINUTES = 5;

    private static ?int $last_written = null;

    public static function listen(): void
    {
        $queue = self::queueFromCommandLine();

        if ($queue === null) {
            return;
        }

        Queue::looping(function () use ($queue) {
            $now = time();

            if (self::$last_written !== null && $now - self::$last_written < self::EVERY_SECONDS) {
                return;
            }

            self::$last_written = $now;

            Cache::put(self::key($queue), $now, now()->addHour());
        });
    }

    public static function key(string $queue): string
    {
        return 'worker_heartbeat:' . $queue;
    }

    public static function beatFor(string $queue): ?int
    {
        $beat = Cache::get(self::key($queue));

        return $beat === null ? null : (int) $beat;
    }

    /**
     * Welke wachtrij deze worker afhandelt. Uit de opdrachtregel, want de lus
     * zelf geeft dat niet mee. Zonder --queue is het de standaardwachtrij.
     */
    private static function queueFromCommandLine(): ?string
    {
        $argv = $_SERVER['argv'] ?? [];

        if (!in_array('queue:work', $argv, true) && !in_array('queue:listen', $argv, true)) {
            return null;
        }

        /** Een enkele job (tests, handmatig) zegt niets over een draaiende worker. */
        if (in_array('--once', $argv, true)) {
            return null;
        }

        foreach ($argv as $index => $argument) {
            if (str_starts_with($argument, '--queue=')) {
                return explode(',', substr($argument, strlen('--queue=')))[0];
            }

            if ($argument === '--queue' && isset($argv[$index + 1])) {
                return explode(',', $argv[$index + 1])[0];
            }
        }

        return 'default';
    }
}

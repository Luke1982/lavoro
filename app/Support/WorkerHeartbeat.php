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

    /**
     * De code waarmee deze worker is opgestart. Eén keer bepaald: net als de
     * instellingen ligt die vast tot een herstart, terwijl het bestand op schijf
     * intussen iets anders kan zeggen.
     */
    private static ?string $code_at_boot = null;

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

            self::$code_at_boot ??= self::codeVersion();

            Cache::put(self::key($queue), $now, now()->addHour());
            Cache::put(self::settingsKey($queue), self::settingsFingerprint(), now()->addHour());
            Cache::put(self::codeKey($queue), self::$code_at_boot, now()->addHour());
        });
    }

    public static function key(string $queue): string
    {
        return 'worker_heartbeat:' . $queue;
    }

    public static function settingsKey(string $queue): string
    {
        return 'worker_settings:' . $queue;
    }

    /**
     * Een vingerafdruk van de instellingen waarmee deze worker draait.
     *
     * Een worker leest .env één keer, bij het opstarten, en houdt dat vast tot
     * hij herstart. Verandert er daarna iets -- een socketpad erbij, een ander
     * wachtwoord -- dan draait hij door op wat hij had, terwijl alles wat de
     * instellingen nu leest denkt dat het klopt. Dat is niet te zien aan de
     * hartslag: die blijft gewoon komen.
     *
     * Alleen wat het werk raakt telt mee, zodat een wijziging elders geen
     * loos alarm oplevert.
     */
    public static function settingsFingerprint(): string
    {
        return md5(serialize([
            config('database.connections.provisioner.username'),
            config('database.connections.provisioner.unix_socket'),
            config('database.connections.provisioner.host'),
            filled(config('database.connections.provisioner.password')),
            config('database.connections.central.database'),
            config('database.connections.central.username'),
            config('database.connections.central.port'),
            config('queue.default'),
            config('cache.default'),
            config('tenancy.database.prefix'),
        ]));
    }

    public static function codeKey(string $queue): string
    {
        return 'worker_code:' . $queue;
    }

    /**
     * Welke code er nu op schijf staat. Php houdt alles vast wat het bij het
     * opstarten heeft ingelezen, dus na een git pull draait een worker rustig
     * door op de oude versie -- met een hartslag die niets laat merken.
     *
     * Leeg als dit geen git-map is; dan valt er niets te vergelijken en wordt
     * er ook niets beweerd.
     */
    public static function codeVersion(): string
    {
        $git = base_path('.git');

        /**
         * In een worktree is .git geen map maar een bestand met daarin waar de
         * echte map staat. Zonder deze stap komt daar niets uit en zou de
         * controle stilletjes niets doen.
         */
        if (is_file($git)) {
            $pointer = trim((string) file_get_contents($git));
            $git = str_starts_with($pointer, 'gitdir: ') ? substr($pointer, 8) : $git;
        }

        $head = $git . '/HEAD';

        if (!is_readable($head)) {
            return '';
        }

        $contents = trim((string) file_get_contents($head));

        if (!str_starts_with($contents, 'ref: ')) {
            return $contents;
        }

        $ref = $git . '/' . substr($contents, 5);

        /**
         * In een worktree staan de takken in de gedeelde map, een niveau hoger
         * dan de eigen HEAD.
         */
        if (!is_readable($ref) && preg_match('#^(.*)/worktrees/[^/]+$#', $git, $found)) {
            $ref = $found[1] . '/' . substr($contents, 5);
        }

        return is_readable($ref) ? trim((string) file_get_contents($ref)) : '';
    }

    public static function codeFor(string $queue): ?string
    {
        $stored = Cache::get(self::codeKey($queue));

        return $stored === null ? null : (string) $stored;
    }

    public static function settingsFor(string $queue): ?string
    {
        $stored = Cache::get(self::settingsKey($queue));

        return $stored === null ? null : (string) $stored;
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

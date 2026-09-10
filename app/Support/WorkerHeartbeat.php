<?php

namespace App\Support;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;

/**
 * Lets a running worker say that it is there.
 *
 * Without this there is no way to tell a live worker from a missing one: an
 * empty queue looks exactly the same either way, and that is precisely the
 * case where work quietly stops happening.
 *
 * Queue::looping fires on every round of the worker, also when there is
 * nothing to do. So this is a heartbeat, not a counter of processed jobs.
 */
final class WorkerHeartbeat
{
    /** How often it writes at most. The loop itself runs every second. */
    private const EVERY_SECONDS = 60;

    public const STALE_AFTER_MINUTES = 5;

    private static ?int $last_written = null;

    /**
     * The code this worker booted with. Determined once: like the settings it
     * is fixed until a restart, while the file on disk may say something else
     * by then.
     */
    private static ?string $code_at_boot = null;

    /** Since when this process has been writing heartbeats. */
    private static ?int $writing_since = null;

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

            self::rememberReporter($queue, $now);
        });
    }

    public static function key(string $queue): string
    {
        return 'worker_heartbeat:' . $queue;
    }

    public static function reportersKey(string $queue): string
    {
        return 'worker_reporters:' . $queue;
    }

    /**
     * Who reports on this queue, per process.
     *
     * "The worker runs older code" does not say which worker. If something old
     * runs alongside the unit, the two take turns writing the same key and the
     * finding stays up however often you restart. With the process, its
     * directory and its boot time next to it, that is visible at a glance.
     */
    private static function rememberReporter(string $queue, int $now): void
    {
        self::$writing_since ??= $now;

        $alive = array_filter(
            (array) Cache::get(self::reportersKey($queue), []),
            fn ($reporter) => is_array($reporter)
                && $now - (int) ($reporter['seen'] ?? 0) < self::STALE_AFTER_MINUTES * 60
        );

        $alive[(string) getmypid()] = [
            'seen' => $now,
            'since' => self::$writing_since,
            'code' => self::$code_at_boot,
            'path' => base_path(),
            'user' => WorkerProcesses::currentUser(),
        ];

        Cache::put(self::reportersKey($queue), $alive, now()->addHour());
    }

    /**
     * One line per process reporting on this queue, to print under a finding.
     * Empty when nothing is known.
     *
     * @return array<int, string>
     */
    public static function reporterLines(string $queue): array
    {
        $lines = [];

        foreach ((array) Cache::get(self::reportersKey($queue), []) as $pid => $reporter) {
            if (!is_array($reporter)) {
                continue;
            }

            $code = (string) ($reporter['code'] ?? '');

            $lines[] = sprintf('pid %s, working since %s, in %s as %s, code %s%s',
                $pid,
                date('d-m H:i', (int) ($reporter['since'] ?? 0)),
                (string) ($reporter['path'] ?? '?'),
                (string) ($reporter['user'] ?? '?'),
                $code === '' ? 'unknown' : substr($code, 0, 8),
                WorkerProcesses::exists((int) $pid) ? '' : ' -- that process is no longer running',
            );
        }

        return $lines;
    }

    public static function settingsKey(string $queue): string
    {
        return 'worker_settings:' . $queue;
    }

    /**
     * A fingerprint of the settings this worker runs with.
     *
     * A worker reads .env once, at boot, and holds on to it until it restarts.
     * Change something after that -- a socket path added, another password --
     * and it keeps running on what it had, while everything that reads the
     * settings now believes they are fine. The heartbeat does not show it: that
     * keeps coming in.
     *
     * Only what touches the work counts, so a change elsewhere does not raise
     * a false alarm.
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
     * Which code is on disk right now. Php holds on to everything it read at
     * boot, so after a git pull a worker happily keeps running the old version
     * -- with a heartbeat that shows nothing of it.
     *
     * Empty when this is not a git directory; then there is nothing to compare
     * and nothing is claimed.
     */
    public static function codeVersion(): string
    {
        $git = base_path('.git');

        /**
         * In a worktree .git is not a directory but a file saying where the
         * real one is. Without this step nothing comes out of it and the check
         * would quietly do nothing.
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
         * In a worktree the branches live in the shared directory, one level
         * up from its own HEAD.
         */
        if (!is_readable($ref) && preg_match('#^(.*)/worktrees/[^/]+$#', $git, $found)) {
            $ref = $found[1] . '/' . substr($contents, 5);
        }

        return is_readable($ref) ? trim((string) file_get_contents($ref)) : '';
    }

    /**
     * The code fingerprints of the processes on this queue that still exist.
     *
     * More reliable than the single key every worker overwrites: two workers on
     * one queue take turns writing it, so that key says whichever wrote last.
     * Empty for workers from before this bookkeeping, which is why the caller
     * falls back to that key.
     *
     * @return array<int, string>
     */
    public static function liveCodes(string $queue): array
    {
        $codes = [];

        foreach ((array) Cache::get(self::reportersKey($queue), []) as $pid => $reporter) {
            if (is_array($reporter) && WorkerProcesses::exists((int) $pid)) {
                $codes[] = (string) ($reporter['code'] ?? '');
            }
        }

        return $codes;
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
     * Which queue this worker handles. From the command line, because the loop
     * itself does not pass it. Without --queue it is the default queue.
     */
    private static function queueFromCommandLine(): ?string
    {
        $argv = $_SERVER['argv'] ?? [];

        if (!in_array('queue:work', $argv, true) && !in_array('queue:listen', $argv, true)) {
            return null;
        }

        /** A single job (tests, by hand) says nothing about a running worker. */
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

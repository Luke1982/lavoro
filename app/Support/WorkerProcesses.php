<?php

namespace App\Support;

/**
 * The queue workers as the operating system knows them.
 *
 * The heartbeat comes from the worker itself, and the very case that goes wrong
 * -- a worker that is never restarted and keeps running old code -- writes
 * nothing new. ps does see it, and /proc says which directory it runs from,
 * which is what makes a second installation on the same queue visible.
 */
final class WorkerProcesses
{
    /** The systemd units the workers run under; see scripts/tenancy/setup-workers.sh. */
    public const UNITS = ['lavoro-worker', 'lavoro-provisioning'];

    /** Php itself does not need pcntl to name the signal; 15 is SIGTERM. */
    private const TERMINATE = 15;

    /**
     * @return array<int, array{pid: int, user: string, seconds: int, queue: string, command: string, directory: string, unit: ?string}>
     */
    public static function forQueue(string $queue): array
    {
        return array_values(array_filter(self::ours(), fn (array $worker) => $worker['queue'] === $queue));
    }

    /**
     * The workers of this installation. Another one on the same server -- the
     * installation a customer was imported from, still running -- shows up in
     * ps just the same. It is not ours to stop, and counting it made "more
     * than one process on this queue" a finding no restart could clear.
     *
     * @return array<int, array{pid: int, user: string, seconds: int, queue: string, command: string, directory: string, unit: ?string}>
     */
    public static function ours(): array
    {
        return array_values(array_filter(self::all(), self::belongsHere(...)));
    }

    /**
     * Started by one of our units, or running from this directory. The
     * directory of another account's process cannot be read from here, and
     * the provisioner is the only other account a worker of ours runs as.
     *
     * @param  array{user: string, directory: string, unit: ?string}  $worker
     */
    public static function belongsHere(array $worker): bool
    {
        if (self::inUnit($worker)) {
            return true;
        }

        if ($worker['directory'] !== '') {
            return $worker['directory'] === (realpath(base_path()) ?: base_path());
        }

        return $worker['user'] === config('database.connections.provisioner.username');
    }

    /**
     * Started by a unit, as opposed to by hand next to it. Told by the
     * process's control group and not by its age: a worker the unit started
     * a minute ago is as old as one somebody started a minute ago.
     *
     * @param  array{unit: ?string}  $worker
     */
    public static function inUnit(array $worker): bool
    {
        return in_array($worker['unit'], self::UNITS, true);
    }

    /**
     * The systemd service a process runs under, read from its control group
     * -- which, unlike its directory, can be read for any account's process.
     */
    public static function unitOf(string $cgroup): ?string
    {
        return preg_match('#/([^/\s]+)\.service$#m', $cgroup, $match) ? $match[1] : null;
    }

    /**
     * By uid and not by name: ps cuts a name down to eight characters, and
     * sudo -u lavoro_+ is nobody.
     *
     * @return array<int, array{pid: int, user: string, seconds: int, queue: string, command: string, directory: string, unit: ?string}>
     */
    public static function all(): array
    {
        if (!function_exists('shell_exec')) {
            return [];
        }

        $found = [];

        foreach (explode("\n", (string) @shell_exec('ps -eo pid=,uid=,etimes=,args= 2>/dev/null')) as $line) {
            if (!str_contains($line, 'artisan queue:work')) {
                continue;
            }

            $columns = preg_split('/\s+/', trim($line), 4);

            if ($columns === false || count($columns) < 4) {
                continue;
            }

            [$pid, $uid, $seconds, $command] = $columns;

            $found[] = [
                'pid' => (int) $pid,
                'user' => self::nameOf((int) $uid),
                'seconds' => (int) $seconds,
                'queue' => preg_match('/--queue[= ]([^\s,]+)/', $command, $match) ? $match[1] : 'default',
                'command' => $command,
                'directory' => (string) (@readlink('/proc/' . (int) $pid . '/cwd') ?: ''),
                'unit' => self::unitOf((string) @file_get_contents('/proc/' . (int) $pid . '/cgroup')),
            ];
        }

        return $found;
    }

    /** @param array{pid: int, user: string, seconds: int, directory: string} $worker */
    public static function describe(array $worker): string
    {
        return sprintf('pid %d, as %s, running for %s, in %s',
            $worker['pid'],
            $worker['user'],
            self::humanDuration($worker['seconds']),
            $worker['directory'] !== '' ? $worker['directory'] : 'an unknown directory',
        );
    }

    public static function exists(int $pid): bool
    {
        return $pid > 0 && file_exists('/proc/' . $pid);
    }

    /**
     * Stopping a process that belongs to another account can only go through
     * php: that is the one thing the admin account may start as someone else
     * without a password. See scripts/tenancy/setup-sudoers.sh.
     *
     * @param  array{pid: int, user: string}  $worker
     */
    public static function stop(array $worker): bool
    {
        if (!self::exists($worker['pid'])) {
            return true;
        }

        if ($worker['user'] === self::currentUser() && function_exists('posix_kill')) {
            return posix_kill($worker['pid'], self::TERMINATE);
        }

        exec(sprintf('sudo -n -u %s %s -r %s 2>/dev/null',
            escapeshellarg($worker['user']),
            escapeshellarg(PHP_BINARY),
            escapeshellarg('posix_kill(' . $worker['pid'] . ', ' . self::TERMINATE . ');'),
        ), $output, $status);

        return $status === 0;
    }

    public static function currentUser(): string
    {
        return function_exists('posix_geteuid') ? self::nameOf(posix_geteuid()) : '?';
    }

    private static function nameOf(int $uid): string
    {
        $account = function_exists('posix_getpwuid') ? posix_getpwuid($uid) : false;

        return is_array($account) ? (string) ($account['name'] ?? $uid) : (string) $uid;
    }

    private static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconds';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' minutes';
        }

        return intdiv($seconds, 3600) . ' hours';
    }
}

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
    /** Php itself does not need pcntl to name the signal; 15 is SIGTERM. */
    private const TERMINATE = 15;

    /**
     * @return array<int, array{pid: int, user: string, seconds: int, queue: string, command: string, directory: string}>
     */
    public static function forQueue(string $queue): array
    {
        return array_values(array_filter(self::all(), fn (array $worker) => $worker['queue'] === $queue));
    }

    /**
     * @return array<int, array{pid: int, user: string, seconds: int, queue: string, command: string, directory: string}>
     */
    public static function all(): array
    {
        if (!function_exists('shell_exec')) {
            return [];
        }

        $found = [];

        foreach (explode("\n", (string) @shell_exec('ps -eo pid=,user=,etimes=,args= 2>/dev/null')) as $line) {
            if (!str_contains($line, 'artisan queue:work')) {
                continue;
            }

            $columns = preg_split('/\s+/', trim($line), 4);

            if ($columns === false || count($columns) < 4) {
                continue;
            }

            [$pid, $user, $seconds, $command] = $columns;

            $found[] = [
                'pid' => (int) $pid,
                'user' => $user,
                'seconds' => (int) $seconds,
                'queue' => preg_match('/--queue[= ]([^\s,]+)/', $command, $match) ? $match[1] : 'default',
                'command' => $command,
                'directory' => (string) (@readlink('/proc/' . (int) $pid . '/cwd') ?: ''),
            ];
        }

        return $found;
    }

    /** @param array{pid: int, user: string, seconds: int, directory: string} $worker */
    public static function describe(array $worker): string
    {
        return sprintf('pid %d, als %s, al %s aan het draaien, in %s',
            $worker['pid'],
            $worker['user'],
            self::humanDuration($worker['seconds']),
            $worker['directory'] !== '' ? $worker['directory'] : 'onbekende map',
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
        if (!function_exists('posix_geteuid')) {
            return '?';
        }

        $account = posix_getpwuid(posix_geteuid());

        return is_array($account) ? (string) ($account['name'] ?? '?') : '?';
    }

    private static function humanDuration(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds . ' seconden';
        }

        if ($seconds < 3600) {
            return intdiv($seconds, 60) . ' minuten';
        }

        return intdiv($seconds, 3600) . ' uur';
    }
}

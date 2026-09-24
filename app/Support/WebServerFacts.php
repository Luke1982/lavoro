<?php

namespace App\Support;

/**
 * What the web server is, written down by the web server itself.
 *
 * Which account serves the pages decides whether uploads land and whether an
 * error reaches the log, so the doctor checks it. It used to read the account
 * off the compiled templates -- but a deploy runs `view:cache`, so those belong
 * to the deploy account from then on, and the doctor cheerfully reported that
 * one. On production it said `lavoro` while php really ran as `nobody`, and
 * every storage check was testing an account that serves nothing.
 *
 * Guessing is the problem, so this does not guess: one real request writes down
 * who it is, and the doctor reads that. No request since the last deploy means
 * the doctor says so instead of inventing an answer.
 *
 * Whether opcache revalidates is written down with it: without that, php keeps
 * the code it started with, and a deploy has to restart it.
 */
final class WebServerFacts
{
    /** Rewritten at most this often; a stat is cheap, a write on every request is not. */
    private const EVERY_SECONDS = 3600;

    public static function path(): string
    {
        return storage_path('framework/web-server.json');
    }

    /**
     * Called from a web request only. Never throws: an account that cannot
     * write here is exactly the problem the doctor reports, and it must not
     * become a 500 on the page the visitor asked for.
     */
    public static function record(): void
    {
        $path = self::path();

        if (is_file($path) && time() - (int) @filemtime($path) < self::EVERY_SECONDS) {
            return;
        }

        $opcache = function_exists('opcache_get_configuration') ? @opcache_get_configuration() : null;
        $directives = is_array($opcache) ? ($opcache['directives'] ?? []) : [];

        @file_put_contents($path, json_encode([
            'account' => function_exists('posix_geteuid') && function_exists('posix_getpwuid')
                ? (posix_getpwuid(posix_geteuid())['name'] ?? null)
                : null,
            'path' => base_path(),
            'php' => PHP_VERSION,
            'sapi' => PHP_SAPI,
            'opcache' => $directives === [] ? null : [
                'enabled' => (bool) ($directives['opcache.enable'] ?? false),
                'validates_timestamps' => (bool) ($directives['opcache.validate_timestamps'] ?? false),
                'revalidate_seconds' => (int) ($directives['opcache.revalidate_freq'] ?? 0),
            ],
            'seen_at' => time(),
        ], JSON_PRETTY_PRINT));
    }

    /**
     * What the last request wrote down, or null when none did -- or when it
     * belongs to another installation sharing this checkout's storage.
     *
     * @return array<string, mixed>|null
     */
    public static function read(): ?array
    {
        $raw = @file_get_contents(self::path());
        $facts = is_string($raw) ? json_decode($raw, true) : null;

        if (!is_array($facts) || ($facts['path'] ?? null) !== base_path()) {
            return null;
        }

        return $facts;
    }
}

<?php

namespace App\Domain\Assistant\Evals;

use App\Domain\Planning\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Where the eval, the probe and the printed fixtures keep their files, and the
 * two things all of them do with one: make a folder and write a file, saying so
 * when either did not work instead of carrying on without it.
 */
final class EvalFiles
{
    /** Holds customer data, so it stays out of git. */
    public static function root(): string
    {
        return base_path('storage/assistant-evals');
    }

    public static function folder(string $path): string
    {
        if (!is_dir($path) && !mkdir($path, 0775, true) && !is_dir($path)) {
            throw new RuntimeException('Kan de map ' . $path . ' niet maken.');
        }

        return $path;
    }

    public static function write(string $path, string $content, int $flags = 0): string
    {
        if (file_put_contents($path, $content, $flags) === false) {
            throw new RuntimeException('Kan ' . $path . ' niet schrijven.');
        }

        return $path;
    }

    /** In local time, to the second, with a few letters so two runs started together do not share a name. */
    public static function moment(): string
    {
        return CarbonImmutable::now(Clock::zone())->format('Y-m-d_H-i-s') . '_' . Str::lower(Str::random(4));
    }
}

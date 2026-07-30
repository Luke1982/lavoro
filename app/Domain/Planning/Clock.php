<?php

namespace App\Domain\Planning;

use Carbon\CarbonImmutable;

/**
 * The one place that knows datetimes are stored in UTC and spoken in local time.
 *
 * config/app.php says so plainly — stored UTC, displayed Europe/Amsterdam — and
 * the planner honours it on the way in and out. The assistant did not, and the
 * damage was not limited to reading times back oddly:
 *
 *   - an appointment asked for at 08:00 was written as 08:00 UTC and appeared at
 *     10:00, which is what somebody noticed;
 *   - the diary was read back two hours early, so it announced times nobody had;
 *   - and availability compared appointments in UTC against working hours and days
 *     off recorded as plain wall-clock, so every free gap it worked out was two
 *     hours off the one on the screen.
 *
 * The last is the worst, because it looks right. A mechanic shown as free at
 * 08:00 is genuinely busy then, and nothing about the answer says so.
 */
final class Clock
{
    public static function zone(): string
    {
        return config('app.display_timezone', 'Europe/Amsterdam');
    }

    /** A stored moment, as the wall clock somebody reads it on. */
    public static function toLocal(mixed $stored): CarbonImmutable
    {
        return CarbonImmutable::parse($stored)->setTimezone(self::zone());
    }

    /**
     * A wall-clock time somebody said, as the moment to store.
     *
     * Null when it is not a time at all, so the caller decides what to say about
     * that rather than being handed a silent today-at-midnight.
     */
    public static function fromLocal(?string $wall): ?CarbonImmutable
    {
        if ($wall === null || !preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}/', $wall)) {
            return null;
        }

        try {
            return CarbonImmutable::parse($wall, self::zone())->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    /** The start of a local day, as the moment to compare stored values against. */
    public static function startOfLocalDay(mixed $day): CarbonImmutable
    {
        return CarbonImmutable::parse($day)->setTimezone(self::zone())->startOfDay()->utc();
    }

    /** Minutes since local midnight, which is the unit DaySegments works in. */
    public static function minutesIntoLocalDay(mixed $stored): int
    {
        $local = self::toLocal($stored);

        return $local->hour * 60 + $local->minute;
    }
}

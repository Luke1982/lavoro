<?php

namespace App\Domain\Planning;

/**
 * Splits a working day into what is bookable and what is not.
 *
 * This is a port of daySegmentsFor in resources/js/Composables/usePlannerGaps.js,
 * which the desktop planner uses. Two implementations of one rule is not an
 * accident: the planner recalculates while an appointment is being dragged, so it
 * has to be in the browser, and the assistant answers from the server, so it has
 * to be here.
 *
 * What keeps them honest is tests/fixtures/day-segments.json, which both read.
 * The day they disagree about whether someone is free on Tuesday, a test says so
 * — which beats a planner being contradicted by the assistant and losing faith in
 * both.
 *
 * Everything is in minutes from midnight. Nothing here touches the database or a
 * clock; turning appointments and unavailability into bands is TechnicianAvailability's
 * job, and keeping that out means this can be checked against the same cases as
 * the JavaScript without either side pretending to have the other's data.
 */
final class DaySegments
{
    /** Anything shorter than this is noise, not room somebody could book. */
    public const MIN_SEGMENT_MINUTES = 15;

    /**
     * @param  array<int, array{start_min: int, end_min: int}>  $busy  Stretches already taken by appointments.
     * @param  array<int, array{start_min: int, end_min: int, label?: ?string}>  $blocked  Stretches the person is unavailable.
     * @return array<int, array{kind: string, start_min: int, end_min: int, label: ?string}>
     */
    public static function for(
        int $work_start_hour,
        int $work_end_hour,
        array $busy,
        array $blocked,
        int $min_segment_minutes = self::MIN_SEGMENT_MINUTES,
    ): array {
        $busy = self::merge($busy);
        $blocked = self::merge($blocked);

        $workday = ['start_min' => $work_start_hour * 60, 'end_min' => $work_end_hour * 60];
        $segments = [];

        foreach (self::subtract($workday, $busy) as $open) {
            /**
             * Blocks are clipped to the open stretch rather than reported whole,
             * so a holiday spanning the week does not claim hours that are
             * already taken by an appointment — those are busy, not blocked, and
             * saying both would count them twice.
             */
            $overlapping = [];

            foreach ($blocked as $band) {
                $start = max($band['start_min'], $open['start_min']);
                $end = min($band['end_min'], $open['end_min']);

                if ($end > $start) {
                    $overlapping[] = ['start_min' => $start, 'end_min' => $end, 'label' => $band['label'] ?? null];
                }
            }

            foreach ($overlapping as $band) {
                $segments[] = ['kind' => 'blocked', ...$band];
            }

            foreach (self::subtract($open, $overlapping) as $free) {
                $segments[] = ['kind' => 'free', 'start_min' => $free['start_min'], 'end_min' => $free['end_min'], 'label' => null];
            }
        }

        $segments = array_values(array_filter(
            $segments,
            fn (array $segment) => $min_segment_minutes <= $segment['end_min'] - $segment['start_min'],
        ));

        usort($segments, fn (array $a, array $b) => $a['start_min'] <=> $b['start_min']);

        return $segments;
    }

    /**
     * Collapses overlapping and touching stretches into the fewest that cover the
     * same ground. Two appointments that meet end to end are one busy stretch,
     * not two with an empty sliver between them.
     *
     * @param  array<int, array{start_min: int, end_min: int, label?: ?string}>  $bands
     * @return array<int, array{start_min: int, end_min: int, label: ?string}>
     */
    private static function merge(array $bands): array
    {
        $sorted = array_values(array_filter($bands, fn (array $band) => $band['end_min'] > $band['start_min']));
        usort($sorted, fn (array $a, array $b) => $a['start_min'] <=> $b['start_min']);

        $merged = [];

        foreach ($sorted as $band) {
            $last = $merged === [] ? null : $merged[count($merged) - 1];

            if ($last !== null && $band['start_min'] <= $last['end_min']) {
                $merged[count($merged) - 1]['end_min'] = max($last['end_min'], $band['end_min']);

                if (blank($merged[count($merged) - 1]['label'])) {
                    $merged[count($merged) - 1]['label'] = $band['label'] ?? null;
                }

                continue;
            }

            $merged[] = [
                'start_min' => $band['start_min'],
                'end_min' => $band['end_min'],
                'label' => $band['label'] ?? null,
            ];
        }

        return $merged;
    }

    /**
     * What is left of a range once the given stretches are taken out of it.
     *
     * @param  array{start_min: int, end_min: int}  $range
     * @param  array<int, array{start_min: int, end_min: int}>  $regions
     * @return array<int, array{start_min: int, end_min: int}>
     */
    private static function subtract(array $range, array $regions): array
    {
        $pieces = [$range];

        foreach ($regions as $region) {
            $remaining = [];

            foreach ($pieces as $piece) {
                if ($region['end_min'] <= $piece['start_min'] || $region['start_min'] >= $piece['end_min']) {
                    $remaining[] = $piece;

                    continue;
                }

                if ($region['start_min'] > $piece['start_min']) {
                    $remaining[] = ['start_min' => $piece['start_min'], 'end_min' => $region['start_min']];
                }

                if ($region['end_min'] < $piece['end_min']) {
                    $remaining[] = ['start_min' => $region['end_min'], 'end_min' => $piece['end_min']];
                }
            }

            $pieces = $remaining;
        }

        return $pieces;
    }
}

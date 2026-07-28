<?php

namespace Tests\Feature\Planning;

use App\Domain\Planning\DaySegments;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Runs the shared cases in tests/fixtures/day-segments.json.
 *
 * The JavaScript the desktop planner uses runs the same file. Neither side is
 * allowed to be right on its own: if these two ever disagree about who is free,
 * a planner gets contradicted by the assistant and stops trusting either.
 */
class DaySegmentsTest extends TestCase
{
    /** @return array<string, array{0: array<string, mixed>, 1: int}> */
    public static function sharedCases(): array
    {
        /**
         * A data provider runs before the application boots, so base_path() does
         * not exist yet. The path is relative to this file instead.
         */
        $fixture = json_decode(
            file_get_contents(dirname(__DIR__, 2) . '/fixtures/day-segments.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        $cases = [];

        foreach ($fixture['cases'] as $case) {
            $cases[$case['name']] = [$case, $fixture['min_segment_minutes']];
        }

        return $cases;
    }

    /**
     * @param  array<string, mixed>  $case
     */
    #[DataProvider('sharedCases')]
    public function test_shared_case(array $case, int $min_segment_minutes): void
    {
        $segments = DaySegments::for(
            work_start_hour: $case['work_start_hour'],
            work_end_hour: $case['work_end_hour'],
            busy: $case['busy'],
            blocked: $case['blocked'],
            min_segment_minutes: $min_segment_minutes,
        );

        $actual = array_map(fn (array $segment) => array_filter([
            'kind' => $segment['kind'],
            'start_min' => $segment['start_min'],
            'end_min' => $segment['end_min'],
            'label' => $segment['label'],
        ], fn ($value) => $value !== null), $segments);

        $this->assertSame($case['expect'], $actual, $case['name']);
    }

    public function test_the_fixture_is_actually_being_read(): void
    {
        $this->assertGreaterThan(10, count(self::sharedCases()), 'the shared fixture looks empty or unreadable');
    }
}

<?php

namespace Tests\Feature\Planning;

use App\Domain\Planning\Clock;
use App\Domain\Planning\DaySegments;
use App\Domain\Planning\TechnicianAvailability;
use App\Models\Customer;
use App\Models\Event;
use App\Models\EventType;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
    use RefreshDatabase;

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

    /**
     * Answering "who is free in the next fortnight" once meant a query per person
     * per day — nearly five hundred for one question. It hid well, because an
     * empty diary is answered on the first day and stops; it only appeared when
     * people were actually busy, which is exactly when the question gets asked.
     */
    public function test_a_fortnight_of_availability_is_one_read_of_the_diary(): void
    {
        $customer = Customer::factory()->create();
        $type = EventType::factory()->create();

        $users = User::factory()->count(5)->create(['plannable' => true]);

        foreach (range(0, 9) as $day) {
            $event = Event::create([
                'event_type_id' => $type->id,
                'status' => 'Gepland',
                'no_service_order' => true,
                'start' => Clock::fromLocal(now()->addDays($day)->format('Y-m-d') . ' 06:00'),
                'end' => Clock::fromLocal(now()->addDays($day)->format('Y-m-d') . ' 20:00'),
            ]);
            $event->syncExecutingUsers($users->pluck('id')->all());
        }

        $availability = app(TechnicianAvailability::class);
        $loaded = $availability->plannableUsers();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $availability->firstSlots($loaded, CarbonImmutable::today(), 120, 14);

        $queries = count(DB::getQueryLog());
        DB::disableQueryLog();

        $this->assertLessThan(
            10,
            $queries,
            'availability went back to the database per person per day (' . $queries . ' queries)'
        );
    }

    public function test_the_fixture_is_actually_being_read(): void
    {
        $this->assertGreaterThan(10, count(self::sharedCases()), 'the shared fixture looks empty or unreadable');
    }
}

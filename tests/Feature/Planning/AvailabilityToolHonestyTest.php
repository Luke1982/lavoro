<?php

namespace Tests\Feature\Planning;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\User;
use App\Models\UserPlanGroup;
use App\Models\UserUnavailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A planning answer that is wrong looks exactly like one that is right, so the
 * tool has to refuse what it cannot do rather than answer it badly.
 *
 * Both cases here were found in the audit log after a real question: a model
 * asking for a group that does not exist, and a model asking for a job longer
 * than a working day. Neither complained, and both produced an answer somebody
 * could have planned a week around.
 */
class AvailabilityToolHonestyTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function ask(array $arguments): ToolResult
    {
        User::factory()->create(['plannable' => true]);

        return app(ToolExecutor::class)->run(
            new ToolCall('find_available_technician', $arguments, $this->admin())
        );
    }

    /**
     * A group that does not exist and a group nobody is in give the same answer —
     * everyone, none of them preferred — and the model reports that as "niemand
     * heeft die specialisatie" without ever having looked one up.
     */
    public function test_a_group_that_does_not_exist_is_refused_with_the_ones_that_do(): void
    {
        UserPlanGroup::create(['name' => 'Electriciens']);

        $result = $this->ask(['plan_group' => 'Loodgieters']);

        $this->assertTrue($result->is_error, 'an unknown group was quietly treated as "nobody qualifies"');
        $this->assertStringContainsString('Electriciens', $result->content);
    }

    public function test_a_group_that_exists_is_accepted(): void
    {
        UserPlanGroup::create(['name' => 'Electriciens']);

        $this->assertFalse($this->ask(['plan_group' => 'Electriciens'])->is_error);
    }

    /**
     * Answering "nobody is free" to a job that cannot fit in any day reads as a
     * full diary rather than as a job that has to be split over days or people.
     */
    public function test_a_job_longer_than_a_working_day_says_so(): void
    {
        $result = $this->ask(['duration_minutes' => 1440]);

        $this->assertTrue($result->is_error, 'an impossible duration came back as "nobody has room"');
        $this->assertStringContainsString('werkdag', $result->content);
    }

    public function test_a_job_that_fits_in_a_day_is_answered_normally(): void
    {
        $this->assertFalse($this->ask(['duration_minutes' => 480])->is_error);
    }

    /**
     * A date the model invented came back as a PHP parse error with a character
     * position in it, which reads as though the diary were broken rather than as
     * though the date were.
     */
    public function test_a_date_it_cannot_read_says_what_it_wanted(): void
    {
        $result = $this->ask(['total_work_minutes' => 480, 'from_date' => 'volgende week dinsdag']);

        $this->assertTrue($result->is_error);
        $this->assertStringContainsString('JJJJ-MM-DD', $result->content);
        $this->assertStringNotContainsString('parse', $result->content);
    }

    /**
     * Filling a crew from outside the group asked for beats having no plan, but
     * saying so is the whole difference between a useful answer and a wrong one.
     */
    public function test_a_crew_it_could_not_fill_from_the_group_says_who_is_not_in_it(): void
    {
        $group = UserPlanGroup::create(['name' => 'Stucadoors']);

        $stucadoor = User::factory()->create(['plannable' => true, 'name' => 'Aaa Stucadoor']);
        $stucadoor->planGroups()->attach($group->id);

        User::factory()->create(['plannable' => true, 'name' => 'Zzz Buitenstaander']);

        $result = app(ToolExecutor::class)->run(new ToolCall('find_available_technician', [
            'total_work_minutes' => 480,
            'plan_group' => 'Stucadoors',
            'crew_size' => 2,
        ], $this->admin()));

        $option = $result->content['options'][0] ?? null;

        $this->assertNotNull($option, 'no crew was offered at all');
        $this->assertContains('Zzz Buitenstaander', $option['crew']);
        $this->assertSame(
            ['Zzz Buitenstaander'],
            $option['not_in_requested_group'],
            'someone outside the requested group was put in the crew without a word',
        );
    }

    /**
     * Answering a question about last January with four workable plans in last
     * January is confident, actionable and useless, and reads exactly like a
     * good answer.
     */
    public function test_it_never_plans_in_the_past(): void
    {
        $result = $this->ask(['total_work_minutes' => 480, 'from_date' => '2020-01-01']);

        $this->assertFalse($result->is_error);

        foreach ($result->content['options'] as $option) {
            foreach ($option['days'] as $day) {
                $this->assertGreaterThanOrEqual(
                    now()->startOfDay()->toDateString(),
                    $day['date'],
                    'a job was planned for a day that has already been',
                );
            }
        }
    }

    /**
     * A crew of nought is not a full diary, and "er is geen ruimte" is the one
     * answer nobody can act on.
     */
    public function test_a_crew_size_that_makes_no_sense_says_so(): void
    {
        foreach ([0, -3, 999] as $size) {
            $result = $this->ask(['total_work_minutes' => 480, 'crew_size' => $size]);

            $this->assertTrue($result->is_error, 'crew_size ' . $size . ' came back as "nobody has room"');
            $this->assertStringContainsString('crew_size', $result->content);
        }
    }

    /**
     * "En als alleen Jeremy en Kenneth kunnen?" has to be computed, not reasoned.
     * Asked to work it out from the availability alone the model got the hours
     * wrong — eight instead of six — and stated them as fact.
     */
    public function test_a_follow_up_about_named_people_is_worked_out_not_guessed(): void
    {
        $wanted = User::factory()->count(2)->create(['plannable' => true]);
        User::factory()->count(3)->create(['plannable' => true]);

        $result = app(ToolExecutor::class)->run(new ToolCall('find_available_technician', [
            'total_work_minutes' => 960,
            'user_ids' => $wanted->pluck('id')->all(),
            'crew_size' => 2,
        ], $this->admin()));

        $option = $result->content['options'][0] ?? null;

        $this->assertNotNull($option);
        $this->assertEqualsCanonicalizing($wanted->pluck('name')->all(), $option['crew']);

        foreach ($option['days'] as $day) {
            $this->assertNotSame($day['from'], $day['until'], 'a day was reported with no hours in it');
        }
    }

    public function test_filtering_on_people_who_cannot_be_planned_says_so(): void
    {
        $desk = User::factory()->create(['plannable' => false]);

        $result = app(ToolExecutor::class)->run(new ToolCall('find_available_technician', [
            'total_work_minutes' => 480,
            'user_ids' => [$desk->id],
        ], $this->admin()));

        $this->assertTrue($result->is_error);
        $this->assertStringContainsString('inplanbaar', $result->content);
    }

    /**
     * A day off can be given up if the person agrees, so a plan that needs one is
     * worth offering — but as a question, with the name and the day in it. Booked
     * over quietly, somebody finds out when the job lands in their calendar.
     */
    public function test_a_plan_that_needs_someones_day_off_says_whose_and_when(): void
    {
        $mechanic = User::factory()->create(['plannable' => true, 'name' => 'Marvin']);

        /** Every day blocked, so there is no plan that does not cost him one. */
        foreach (range(0, 6) as $weekday) {
            UserUnavailability::create([
                'user_id' => $mechanic->id,
                'type' => 'recurring',
                'repeat' => 'weekly',
                'day_of_week' => $weekday,
                'start_time' => null,
                'end_time' => null,
                'label' => 'Papadag',
            ]);
        }

        $result = app(ToolExecutor::class)->run(new ToolCall('find_available_technician', [
            'total_work_minutes' => 480,
            'crew_size' => 1,
        ], $this->admin()));

        $option = $result->content['options'][0] ?? null;

        $this->assertNotNull($option, 'no plan was offered at all, not even one to ask about');
        $this->assertNotEmpty($option['costs_someone_their_day_off'], 'a day off was booked over without a word');
        $this->assertSame('Marvin', $option['costs_someone_their_day_off'][0]['who']);
        $this->assertContains('Papadag', $option['costs_someone_their_day_off'][0]['reason']);
    }

    /**
     * The flag has to mean something. A plan that costs nobody anything must not
     * carry a warning, or the warning stops being read.
     */
    public function test_a_plan_that_costs_nobody_anything_carries_no_warning(): void
    {
        User::factory()->create(['plannable' => true]);

        $result = app(ToolExecutor::class)->run(new ToolCall('find_available_technician', [
            'total_work_minutes' => 480,
            'crew_size' => 1,
        ], $this->admin()));

        $this->assertSame([], $result->content['options'][0]['costs_someone_their_day_off']);
    }

    /**
     * Availability is keyed by name, and names are not unique. Two mechanics
     * called Jan became one, and the one that vanished looked like somebody with
     * no free time rather than somebody missing.
     */
    public function test_two_mechanics_with_the_same_name_both_appear(): void
    {
        User::factory()->count(2)->create(['plannable' => true, 'name' => 'Jan']);

        $result = app(ToolExecutor::class)->run(new ToolCall('find_available_technician', [
            'total_work_minutes' => 480,
        ], $this->admin()));

        $listed = array_keys($result->content['availability']);
        $jans = array_values(array_filter($listed, fn (string $name) => str_starts_with($name, 'Jan')));

        $this->assertCount(2, $jans, 'one of two mechanics with the same name was silently dropped');
    }

    /**
     * Narrowing to two people is done by user_id, so the id has to be somewhere
     * in the answer. Without it the only way left is working the hours out by
     * hand, and it got them wrong doing exactly that.
     */
    public function test_availability_carries_the_id_the_follow_up_needs(): void
    {
        $mechanic = User::factory()->create(['plannable' => true, 'name' => 'Jeremy']);

        $result = app(ToolExecutor::class)->run(new ToolCall('find_available_technician', [
            'total_work_minutes' => 480,
        ], $this->admin()));

        $this->assertSame($mechanic->id, $result->content['availability']['Jeremy']['user_id']);
        $this->assertNotEmpty($result->content['availability']['Jeremy']['vrij']);
    }
}

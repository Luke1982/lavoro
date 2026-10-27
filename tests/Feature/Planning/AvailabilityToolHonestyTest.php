<?php

namespace Tests\Feature\Planning;

use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolResult;
use App\Models\User;
use App\Models\UserPlanGroup;
use App\Models\UserUnavailability;
use Carbon\CarbonImmutable;
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

    /**
     * Which day of the week it is, said rather than left to be worked out.
     *
     * Handed bare dates the model does the sum itself and gets it wrong — "dinsdag
     * 31 juli" for a Friday, inside an answer that was otherwise right. That is the
     * kind of mistake only somebody who already knows will catch.
     */
    public function test_every_day_it_offers_says_which_day_of_the_week_it_is(): void
    {
        $user = $this->admin();
        User::factory()->create(['plannable' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-07-31 09:00', 'Europe/Amsterdam'));

        $answer = app(ToolExecutor::class)->run(new ToolCall(
            'find_available_technician',
            ['total_work_minutes' => 240, 'crew_size' => 1],
            $user,
        ))->content;

        $first = $answer['options'][0]['days'][0];

        $this->assertSame('2026-07-31', $first['date']);

        /**
         * Today is said outright too. Told the date and shown the weekday, the
         * model still announced a plan starting today as "morgen al beginnen" —
         * relative words are arithmetic, and its arithmetic is not to be trusted.
         */
        $this->assertSame('vandaag (vrijdag)', $first['weekday'], 'the model is left to work out what vandaag is');

        $windows = collect($answer['availability'])->first()['vrij'];

        $this->assertStringContainsString('morgen (zaterdag)', $windows['2026-08-01'] ?? '');
        $this->assertStringContainsString('zondag', $windows['2026-08-02'] ?? '');
        $this->assertStringNotContainsString('morgen', $windows['2026-08-02'] ?? '', 'overmorgen was called morgen');

        $this->travelBack();
    }

    /**
     * A Saturday is a day like any other here, and plenty of them are worked.
     *
     * Who is free when is already recorded per person as recurring unavailability,
     * so a blanket rule about weekends in this tool would talk over real data with
     * an assumption — and did: it announced that nobody is rostered at the weekend
     * and told the model to ask permission for a day people work by choice.
     */
    public function test_it_invents_no_rule_about_working_at_the_weekend(): void
    {
        $user = $this->admin();
        User::factory()->create(['plannable' => true]);
        $this->travelTo(CarbonImmutable::parse('2026-07-31 09:00', 'Europe/Amsterdam'));

        $answer = app(ToolExecutor::class)->run(new ToolCall(
            'find_available_technician',
            ['total_work_minutes' => 3000, 'crew_size' => 1],
            $user,
        ))->content;

        $this->assertArrayNotHasKey('weekend_days_in_these_options', $answer);
        $this->assertStringNotContainsString('weekend', $answer['note']);
        $this->assertStringNotContainsString('ingeroosterd', $answer['note']);

        /** The day is still named, so anybody reading it can see it is a Saturday. */
        $days = collect($answer['options'])->flatMap(fn (array $option) => $option['days']);

        $this->assertTrue(
            $days->contains(fn (array $day) => str_contains($day['weekday'], 'zaterdag')),
            'a Saturday in the plan went out without being named',
        );

        $this->travelBack();
    }

    /**
     * The numbers beside the names, in the same object and the same order.
     *
     * The crew came back as names alone, so a model told "Alptug & Jeremy" and
     * then asked to plan it had to find their ids somewhere else. It took two off
     * a different list and proposed an appointment for Ferhat and Jimmy — right
     * day, wrong men, with the names in its own sentence still saying Alptug and
     * Jeremy. Nothing about the proposal looked wrong.
     */
    public function test_a_crew_carries_the_numbers_the_next_step_needs(): void
    {
        $user = $this->admin();
        $crew = User::factory()->count(2)->create(['plannable' => true]);

        $option = app(ToolExecutor::class)->run(new ToolCall(
            'find_available_technician',
            ['total_work_minutes' => 240, 'crew_size' => 2],
            $user,
        ))->content['options'][0];

        $this->assertCount(2, $option['crew_user_ids']);
        $this->assertSame(count($option['crew']), count($option['crew_user_ids']), 'names and numbers disagree');

        /** Same order, or the pairing is a coin toss. */
        foreach ($option['crew_user_ids'] as $index => $id) {
            $this->assertSame(User::find($id)->name, $option['crew'][$index]);
        }

        $this->assertEmpty(array_diff($option['crew_user_ids'], $crew->pluck('id')->push($user->id)->all()));
    }

    /**
     * The plans come back as buttons, not as material for a numbered list.
     *
     * Which crew takes the job was left to the model to ask nicely, and asking
     * nicely happens about half the time — the other half was prose with nothing
     * to click. The ambiguity is in this data, so this data offers the choice,
     * and the reference carries the monteurs' numbers and the dates so the click
     * hands the follow-up everything. Looked up again by name is how Alptug and
     * Jeremy once became Ferhat and Jimmy.
     */
    public function test_more_than_one_plan_offers_itself_as_buttons(): void
    {
        $user = $this->admin();
        User::factory()->count(2)->create(['plannable' => true]);

        $content = app(ToolExecutor::class)->run(new ToolCall(
            'find_available_technician',
            ['total_work_minutes' => 960],
            $user,
        ))->content;

        $this->assertGreaterThan(1, count($content['options']), 'one plan proves nothing about choosing');
        $this->assertNotNull($content['choice'], 'the plans were left for the model to ask about nicely');
        $this->assertCount(count($content['options']), $content['choice']['options']);

        foreach ($content['choice']['options'] as $index => $button) {
            /**
             * The exact phrase, not the digits somewhere in the string: a
             * single-digit id also occurs inside "2026-08-03", so checking the
             * digits let a reference full of names pass for one full of numbers.
             */
            $this->assertStringContainsString(
                'monteurs ' . implode(', ', $content['options'][$index]['crew_user_ids']),
                $button['reference'],
                'a click would lose the crew',
            );

            $this->assertStringContainsString($content['options'][$index]['days'][0]['date'], $button['reference']);
        }
    }

    /** One plan is a proposal, not a choice; a single button asking "which one?" is noise. */
    public function test_a_single_plan_is_not_dressed_up_as_a_choice(): void
    {
        $user = $this->admin();
        User::factory()->create(['plannable' => true]);

        $content = app(ToolExecutor::class)->run(new ToolCall(
            'find_available_technician',
            ['total_work_minutes' => 240, 'crew_size' => 1],
            $user,
        ))->content;

        $this->assertNull($content['choice']);
    }
}

<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantQuestion;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Until now nothing was kept and a refresh lost the lot.
 *
 * That was defensible while the assistant only read things. It stopped being so
 * the moment it could make an appointment: the audit could show that create_event
 * ran with a date and a mechanic, and nothing at all about what was asked for.
 */
class AssistantHistoryTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function ask(string $question, ?int $user_id = null, ?string $conversation = null): AssistantQuestion
    {
        return AssistantQuestion::create([
            'user_id' => $user_id ?? $this->userWith('assistant.use')->id,
            'conversation_id' => $conversation ?? (string) Str::uuid(),
            'question' => $question,
            'answer' => 'Een antwoord.',
        ]);
    }

    public function test_somebody_can_read_back_what_they_asked(): void
    {
        $user = $this->userWith('assistant.use');
        $this->ask('Wie kan er dinsdag?', $user->id);

        $this->actingAs($user)
            ->getJson('/assistant/history')
            ->assertOk()
            ->assertJsonPath('conversations.0.title', 'Wie kan er dinsdag?');
    }

    /**
     * A transcript is a record of somebody's working day. Being allowed to use
     * the assistant is not the same as being allowed to read everyone's use of it.
     */
    public function test_nobody_reads_anybody_elses(): void
    {
        $this->ask('Wat verdient Jeremy?', $this->userWith('assistant.use')->id);

        $this->actingAs($this->userWith('assistant.use'))
            ->getJson('/assistant/history')
            ->assertOk()
            ->assertJsonCount(0, 'conversations');
    }

    public function test_history_is_behind_the_same_permission(): void
    {
        $this->actingAs($this->admin())->getJson('/assistant/history')->assertForbidden();
    }

    /**
     * Two conversations touched in the same second used to sort arbitrarily, so
     * "most recent first" was only true to the second.
     */
    public function test_the_newest_conversation_comes_first(): void
    {
        $user = $this->userWith('assistant.use');
        $this->ask('eerste', $user->id);
        $this->ask('laatste', $user->id);

        $this->actingAs($user)
            ->getJson('/assistant/history')
            ->assertJsonCount(2, 'conversations')
            ->assertJsonPath('conversations.0.title', 'laatste')
            ->assertJsonPath('conversations.1.title', 'eerste');
    }

    /**
     * These rows are a record of a working day, not a permanent one. Nothing runs
     * this on its own: the scheduler here needs a server cron that is not wired up.
     */
    public function test_pruning_keeps_the_recent_and_drops_the_old(): void
    {
        $user = $this->userWith('assistant.use');

        $old = $this->ask('vorig jaar', $user->id);
        $old->forceFill(['created_at' => now()->subMonths(9)])->saveQuietly();

        $this->ask('deze week', $user->id);

        $this->artisan('assistant:prune', ['--months' => 6])->assertSuccessful();

        $this->assertSame(1, AssistantQuestion::count());
        $this->assertSame('deze week', AssistantQuestion::sole()->question);
    }

    /**
     * Thirty full answers is most of half a megabyte, and this list exists to
     * find a question again — the answer is read by asking it once more.
     */
    public function test_the_list_carries_a_taste_of_the_answer_not_all_of_it(): void
    {
        $user = $this->userWith('assistant.use');

        AssistantQuestion::create([
            'user_id' => $user->id,
            'conversation_id' => (string) Str::uuid(),
            'question' => 'lange vraag',
            'answer' => str_repeat('Een heel lang antwoord. ', 500),
        ]);

        $response = $this->actingAs($user)->getJson('/assistant/history');

        $response->assertOk();

        $this->assertLessThan(1000, mb_strlen($response->json('conversations.0.preview')));
    }

    /**
     * The path is kept for context and the column holds 255, while the request
     * accepts far more. Failing on it would lose the whole question.
     */
    public function test_a_page_too_long_for_the_column_does_not_lose_the_question(): void
    {
        $user = $this->userWith('assistant.use');

        AssistantQuestion::create([
            'user_id' => $user->id,
            'question' => 'Wie kan er dinsdag?',
            'page' => '/' . str_repeat('a', 400),
        ]);

        $this->assertSame(255, mb_strlen(AssistantQuestion::sole()->page));
    }

    /**
     * The turn that follows a confirmation is where writes get proposed, so it is
     * the half most worth having a record of — and it was not being written down at
     * all.
     */
    public function test_a_continuation_is_recorded(): void
    {
        $user = $this->userWith('assistant.use');

        AssistantQuestion::create([
            'user_id' => $user->id,
            'conversation_id' => (string) Str::uuid(),
            'question' => '(verder na bevestiging)',
            'is_continuation' => true,
            'answer' => 'Taak klaargezet.',
        ]);

        $this->assertSame(1, AssistantQuestion::count());
        $this->assertTrue(AssistantQuestion::sole()->is_continuation);
    }

    /**
     * Kept out of the panel, because that answers "what did I ask" and nobody
     * asked these.
     */
    public function test_a_continuation_is_not_offered_as_something_you_asked(): void
    {
        $user = $this->userWith('assistant.use');
        $thread = (string) Str::uuid();
        $this->ask('Maak een werkbon', $user->id, $thread);

        AssistantQuestion::create([
            'user_id' => $user->id,
            'conversation_id' => $thread,
            'question' => '(verder na bevestiging)',
            'is_continuation' => true,
            'answer' => 'Taak klaargezet.',
        ]);

        $this->actingAs($user)
            ->getJson('/assistant/history')
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.title', 'Maak een werkbon');
    }

    /**
     * Listed one turn per row, "eerste" and "Nee het is de eerste klant" sat there
     * as separate things, and neither means anything away from its thread.
     */
    public function test_the_turns_of_one_conversation_are_one_entry(): void
    {
        $user = $this->userWith('assistant.use');
        $thread = (string) Str::uuid();

        $this->ask('Ik moet een airco plaatsen in Ede', $user->id, $thread);
        $this->ask('eerste', $user->id, $thread);
        $this->ask('4 uur met 1 man', $user->id, $thread);

        $this->actingAs($user)
            ->getJson('/assistant/history')
            ->assertOk()
            ->assertJsonCount(1, 'conversations')
            ->assertJsonPath('conversations.0.title', 'Ik moet een airco plaatsen in Ede')
            ->assertJsonPath('conversations.0.turns', 3);
    }

    /**
     * Clicking used to fill the box with the opening question, which threw the
     * thread away and left somebody to type their way back to where they were.
     */
    public function test_a_conversation_comes_back_in_full_and_in_order(): void
    {
        $user = $this->userWith('assistant.use');
        $thread = (string) Str::uuid();

        $this->ask('Eerste vraag', $user->id, $thread);
        AssistantQuestion::create([
            'user_id' => $user->id,
            'conversation_id' => $thread,
            'question' => '(verder na bevestiging)',
            'is_continuation' => true,
            'answer' => 'Taak klaargezet.',
        ]);
        $this->ask('Tweede vraag', $user->id, $thread);

        $response = $this->actingAs($user)->getJson('/assistant/history/' . $thread)->assertOk();

        $this->assertCount(3, $response->json('turns'));
        $this->assertSame('Eerste vraag', $response->json('turns.0.question'));
        $this->assertSame('', $response->json('turns.1.question'), 'a continuation is nobody\'s question');
        $this->assertSame('Taak klaargezet.', $response->json('turns.1.answer'));
        $this->assertSame('Tweede vraag', $response->json('turns.2.question'));
    }

    public function test_nobody_opens_a_conversation_that_is_not_theirs(): void
    {
        $thread = (string) Str::uuid();
        $this->ask('Wat verdient Jeremy?', $this->userWith('assistant.use')->id, $thread);

        $this->actingAs($this->userWith('assistant.use'))
            ->getJson('/assistant/history/' . $thread)
            ->assertNotFound();
    }

    public function test_a_dry_run_removes_nothing(): void
    {
        $user = $this->userWith('assistant.use');
        $old = $this->ask('vorig jaar', $user->id);
        $old->forceFill(['created_at' => now()->subMonths(9)])->saveQuietly();

        $this->artisan('assistant:prune', ['--months' => 6, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, AssistantQuestion::count());
    }

    /**
     * The list is read by a browser, which is not told what clock the server keeps.
     * Handed a bare "2026-07-30 14:18:40" it assumes its own, and a conversation had
     * at four in the afternoon is filed at two — or, just after midnight, yesterday.
     */
    public function test_a_thread_is_stamped_with_a_moment_not_a_bare_datetime(): void
    {
        $user = $this->userWith('assistant.use');

        $this->travelTo(CarbonImmutable::parse('2026-07-30 14:18:40', 'UTC'));

        AssistantQuestion::create([
            'user_id' => $user->id,
            'conversation_id' => 'abc',
            'question' => 'Wie kan er dinsdag?',
            'answer' => 'Jeremy.',
        ]);

        $stamped = $this->actingAs($user)->getJson('/assistant/history')
            ->json('conversations.0.last_at');

        $this->assertNotNull($stamped, 'the thread came back without a moment');
        $this->assertMatchesRegularExpression(
            '/(Z|[+-]\\d{2}:\\d{2})$/',
            $stamped,
            'no offset on it, so a browser will read it as its own local time',
        );
        $this->assertTrue(
            CarbonImmutable::parse($stamped)->equalTo(CarbonImmutable::parse('2026-07-30 14:18:40', 'UTC')),
            'the moment moved on the way out',
        );

        $this->travelBack();
    }

    /**
     * The command trimming these is only a promise if something runs it.
     *
     * It existed for as long as the assistant did and was scheduled nowhere, so
     * every transcript of everybody's working day was kept for ever while the
     * thing meant to trim them was never once invoked. Its own default says six
     * months, which made that an intention rather than a fact.
     */
    public function test_something_actually_runs_the_pruning(): void
    {
        $scheduled = collect(app(Schedule::class)->events())
            ->map(fn ($event) => $event->command ?? $event->description ?? '')
            ->filter(fn (string $command) => str_contains($command, 'assistant:prune'));

        $this->assertNotEmpty($scheduled, 'nothing invokes assistant:prune, so nothing is ever pruned');
    }
}

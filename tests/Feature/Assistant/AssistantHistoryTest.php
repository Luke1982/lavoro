<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantQuestion;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    private function ask(string $question, ?int $user_id = null): AssistantQuestion
    {
        return AssistantQuestion::create([
            'user_id' => $user_id ?? $this->userWith('assistant.use')->id,
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
            ->assertJsonPath('questions.0.question', 'Wie kan er dinsdag?');
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
            ->assertJsonCount(0, 'questions');
    }

    public function test_history_is_behind_the_same_permission(): void
    {
        $this->actingAs($this->admin())->getJson('/assistant/history')->assertForbidden();
    }

    public function test_the_newest_question_comes_first(): void
    {
        $user = $this->userWith('assistant.use');
        $this->ask('eerste', $user->id);
        $this->ask('laatste', $user->id);

        $this->actingAs($user)
            ->getJson('/assistant/history')
            ->assertJsonPath('questions.0.question', 'laatste');
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
            'question' => 'lange vraag',
            'answer' => str_repeat('Een heel lang antwoord. ', 500),
        ]);

        $response = $this->actingAs($user)->getJson('/assistant/history');

        $response->assertOk()->assertJsonPath('questions.0.answer_truncated', true);

        $this->assertLessThan(1000, mb_strlen($response->json('questions.0.answer')));
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

    public function test_a_dry_run_removes_nothing(): void
    {
        $user = $this->userWith('assistant.use');
        $old = $this->ask('vorig jaar', $user->id);
        $old->forceFill(['created_at' => now()->subMonths(9)])->saveQuietly();

        $this->artisan('assistant:prune', ['--months' => 6, '--dry-run' => true])->assertSuccessful();

        $this->assertSame(1, AssistantQuestion::count());
    }
}

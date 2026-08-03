<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantQuestion;
use App\Models\AssistantToolCall;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * A conversation written out so somebody can find out what went wrong in it.
 *
 * The prose can be copied off the screen. What cannot is the arguments the tools
 * were called with and what they gave back — and that is where every fault found
 * in this assistant has been, behind an answer that read perfectly well.
 */
class ConversationReportTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private const THREAD = '7c1e0f44-1111-4222-8333-444444444444';

    private function turn(int $user_id, array $attributes = []): AssistantQuestion
    {
        /** Merged, not unioned: array union keeps the left-hand key and drops the override. */
        return AssistantQuestion::create(array_merge([
            'user_id' => $user_id,
            'conversation_id' => self::THREAD,
            'question' => 'Welke klanten zijn er in Meteren?',
            'answer' => 'Er zijn er 25.',
            'tools' => [['name' => 'find_customer', 'arguments' => ['city' => 'Meteren'], 'failed' => false]],
        ], $attributes));
    }

    public function test_it_writes_the_arguments_and_what_came_back(): void
    {
        Storage::fake('local');

        $user = $this->userWith('assistant.use');
        $this->turn($user->id);

        AssistantToolCall::create([
            'user_id' => $user->id,
            'tool' => 'find_customer',
            'arguments' => ['city' => 'Meteren'],
            'outcome' => 'ok',
            'result' => '{"matches":80,"per_place":{"Meteren":25}}',
            'duration_ms' => 7,
        ]);

        $response = $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD]);

        $response->assertOk();

        $written = Storage::disk('local')->get($response->json('path'));

        $this->assertStringContainsString('Welke klanten zijn er in Meteren?', $written);
        $this->assertStringContainsString('"city": "Meteren"', $written, 'the arguments were left out');
        $this->assertStringContainsString('"matches":80', $written, 'what the tool returned was left out');
        $this->assertStringContainsString('Er zijn er 25.', $written);
    }

    /**
     * Called five times, a tool has five answers. Keyed by name alone the report
     * showed the last one against every call, which is a diagnostic that quietly
     * lies — worse than one that says nothing.
     */
    public function test_a_tool_called_twice_keeps_both_of_its_answers(): void
    {
        Storage::fake('local');

        $user = $this->userWith('assistant.use');

        $this->turn($user->id, [
            'tools' => [
                ['name' => 'find_customer', 'arguments' => ['city' => 'Meteren'], 'failed' => false],
                ['name' => 'find_customer', 'arguments' => ['query' => 'label'], 'failed' => false],
            ],
        ]);

        foreach (['EERSTE ANTWOORD', 'TWEEDE ANTWOORD'] as $said) {
            AssistantToolCall::create([
                'user_id' => $user->id,
                'tool' => 'find_customer',
                'arguments' => [],
                'outcome' => 'ok',
                'result' => $said,
                'duration_ms' => 3,
            ]);
        }

        $response = $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD]);
        $written = Storage::disk('local')->get($response->json('path'));

        $this->assertStringContainsString('EERSTE ANTWOORD', $written);
        $this->assertStringContainsString('TWEEDE ANTWOORD', $written, 'the second call was given the first answer');
    }

    public function test_a_conversation_that_is_not_yours_cannot_be_reported(): void
    {
        Storage::fake('local');

        $mine = $this->userWith('assistant.use');
        $theirs = $this->userWith('assistant.use');

        $this->turn($theirs->id);

        $this->actingAs($mine)
            ->postJson('/assistant/report', ['conversation' => self::THREAD])
            ->assertStatus(404);

        $this->assertEmpty(Storage::disk('local')->allFiles(), 'somebody else\'s conversation was written out');
    }

    public function test_reporting_needs_the_assistant_permission(): void
    {
        Storage::fake('local');

        $outsider = $this->userWith('customer.read');

        $this->actingAs($outsider)
            ->postJson('/assistant/report', ['conversation' => self::THREAD])
            ->assertStatus(403);
    }

    /** A failed turn is the one most worth reporting, so it must not be silently dropped. */
    public function test_a_turn_that_failed_says_so(): void
    {
        Storage::fake('local');

        $user = $this->userWith('assistant.use');
        $this->turn($user->id, ['answer' => null, 'failure' => 'Gestopt na 6 tool-rondes.']);

        $response = $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD]);

        $this->assertStringContainsString(
            'Gestopt na 6 tool-rondes.',
            Storage::disk('local')->get($response->json('path')),
        );
    }

    /**
     * These are the fullest copy of a conversation there is — the questions, the
     * answers, and what the tools were handed. Keeping them longer than the rows
     * they were written from would put the retention rule the wrong way round.
     */
    public function test_old_reports_are_pruned_like_everything_else(): void
    {
        Storage::fake('local');

        $user = $this->userWith('assistant.use');
        $this->turn($user->id);

        $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD])->assertOk();

        $this->assertCount(1, Storage::disk('local')->allFiles());

        /** The file is fresh, so nothing should go yet. */
        $this->artisan('assistant:prune', ['--months' => 6])->assertSuccessful();
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'a report was thrown away on the day it was made');

        /** And once it is past its keeping, it goes. */
        $this->travelTo(now()->addMonths(9));
        $this->artisan('assistant:prune', ['--months' => 6])->assertSuccessful();
        $this->assertCount(0, Storage::disk('local')->allFiles(), 'an old report outlived its conversation');

        $this->travelBack();
    }
}

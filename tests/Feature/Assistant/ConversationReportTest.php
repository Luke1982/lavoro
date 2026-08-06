<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\ModelToolCall;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\ConversationPhotos;
use App\Domain\Assistant\ModelPicker;
use App\Mail\AssistantConversationReportedMail;
use App\Models\AssistantQuestion;
use App\Models\AssistantToolCall;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
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
        /**
         * Merged, not unioned: array union keeps the left-hand key and drops the
         * override. And tools is a list of bare names, because that is what the
         * application writes — the first fixtures here stored full call arrays,
         * so the tests passed while every real report rendered "Tool ?".
         */
        return AssistantQuestion::create(array_merge([
            'user_id' => $user_id,
            'conversation_id' => self::THREAD,
            'question' => 'Welke klanten zijn er in Meteren?',
            'answer' => 'Er zijn er 25.',
            'tools' => ['find_customer'],
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

        $this->turn($user->id, ['tools' => ['find_customer', 'find_customer']]);

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

    /**
     * Delivered as well as filed. The disk is private, which is exactly where
     * nobody looks; a melding belongs in an inbox with the file attached.
     */
    public function test_a_melding_lands_in_the_inbox_with_the_file_attached(): void
    {
        Storage::fake('local');
        Mail::fake();
        config(['assistant.reports_mail_to' => 'info@majorlabel.nl']);

        $user = $this->userWith('assistant.use');
        $this->turn($user->id);

        $response = $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD]);

        $response->assertOk();
        $this->assertStringContainsString('gemaild', $response->json('message'));

        Mail::assertSent(AssistantConversationReportedMail::class, function (AssistantConversationReportedMail $mail) use ($user) {
            $built = $mail->build();

            return $mail->hasTo('info@majorlabel.nl')
                && str_contains($built->subject, $user->name)
                && collect($built->rawAttachments)->contains(
                    fn (array $attached) => str_ends_with($attached['name'], '.md')
                        && str_contains($attached['data'], 'Welke klanten zijn er in Meteren?')
                );
        });
    }

    /** A broken mailserver must not turn a successful melding into an error. */
    public function test_a_dead_mailserver_does_not_break_the_melding(): void
    {
        Storage::fake('local');
        config(['assistant.reports_mail_to' => 'info@majorlabel.nl']);

        Mail::shouldReceive('to')->andThrow(new \RuntimeException('mailserver weg'));

        $user = $this->userWith('assistant.use');
        $this->turn($user->id);

        $response = $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD]);

        $response->assertOk();
        $this->assertStringNotContainsString('gemaild', $response->json('message'));
        $this->assertCount(1, Storage::disk('local')->allFiles(), 'the report itself was lost with the mail');
    }

    /** Nobody configured means nothing sent, quietly. */
    public function test_no_recipient_means_no_mail(): void
    {
        Storage::fake('local');
        Mail::fake();
        config(['assistant.reports_mail_to' => null]);

        $user = $this->userWith('assistant.use');
        $this->turn($user->id);

        $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD])->assertOk();

        Mail::assertNothingSent();
    }

    /**
     * The reason travels with the report, first in the file and again in the
     * mail. The transcript says what happened; only the melder can say what
     * should have happened instead, and that sentence is the investigator's brief.
     */
    public function test_the_reason_rides_along_in_file_and_mail(): void
    {
        Storage::fake('local');
        Mail::fake();
        config(['assistant.reports_mail_to' => 'info@majorlabel.nl']);

        $user = $this->userWith('assistant.use');
        $this->turn($user->id);

        $response = $this->actingAs($user)->postJson('/assistant/report', [
            'conversation' => self::THREAD,
            'reason' => 'Hij noemde de verkeerde klant.',
        ]);

        $written = Storage::disk('local')->get($response->json('path'));

        $this->assertStringContainsString('## Waarom gemeld', $written);
        $this->assertStringContainsString('Hij noemde de verkeerde klant.', $written);

        Mail::assertSent(
            AssistantConversationReportedMail::class,
            fn (AssistantConversationReportedMail $mail) => str_contains($mail->build()->render(), 'Hij noemde de verkeerde klant.'),
        );
    }

    /** No reason given is no section at all, not an empty heading. */
    public function test_no_reason_means_no_waarom_section(): void
    {
        Storage::fake('local');
        Mail::fake();

        $user = $this->userWith('assistant.use');
        $this->turn($user->id);

        $response = $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD]);

        $this->assertStringNotContainsString('Waarom gemeld', Storage::disk('local')->get($response->json('path')));
    }

    /** A reason is a sentence, not an essay pasted past the column. */
    public function test_an_overlong_reason_is_refused(): void
    {
        Storage::fake('local');

        $user = $this->userWith('assistant.use');
        $this->turn($user->id);

        $this->actingAs($user)->postJson('/assistant/report', [
            'conversation' => self::THREAD,
            'reason' => str_repeat('a', 2001),
        ])->assertStatus(422);

        $this->assertEmpty(Storage::disk('local')->allFiles(), 'the report was written before validation');
    }

    /**
     * Through the real ask endpoint, because the fixture-only tests proved
     * nothing: they stored tool calls in a shape the application never writes,
     * passed, and every real report said "Tool ?" with empty arguments.
     */
    public function test_a_report_of_a_real_conversation_carries_the_tools(): void
    {
        Storage::fake('local');
        Mail::fake();

        $this->app->bind(
            ModelPicker::class,
            fn () => ModelPicker::fixed(new ReportSpyModel)
        );

        $user = $this->userWithPermissions('assistant.use', 'customer.read');
        Customer::factory()->create(['name' => 'Majorlabel', 'city' => 'Meteren']);

        $this->actingAs($user)->postJson('/assistant/ask', [
            'question' => 'Zoek de klant met label in de naam',
            'conversation' => self::THREAD,
        ])->assertOk();

        $response = $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD]);
        $written = Storage::disk('local')->get($response->json('path'));

        $this->assertStringContainsString('### Tool `find_customer`', $written, 'the tool lost its name');
        $this->assertStringContainsString('"query": "label"', $written, 'the arguments were left out');
        $this->assertStringContainsString('Kwam terug (ok)', $written, 'what the tool returned was left out');
        $this->assertStringContainsString('Majorlabel', $written);
    }

    /**
     * A turn's tools run during the minutes before its question row is written.
     * Anchored on the row itself, the first turn's calls fell outside the window
     * and every pairing shifted one whole turn — a production report showed turn
     * 3's arguments under turn 1 and nothing at all under turn 3.
     */
    public function test_tools_that_ran_before_the_first_row_was_written_still_pair(): void
    {
        Storage::fake('local');

        $user = $this->userWith('assistant.use');
        $turn = $this->turn($user->id);

        $early = AssistantToolCall::create([
            'user_id' => $user->id,
            'tool' => 'find_customer',
            'arguments' => ['city' => 'Meteren'],
            'outcome' => 'ok',
            'result' => 'HET ECHTE RESULTAAT VAN BEURT EEN',
            'duration_ms' => 5,
        ]);
        $early->forceFill(['created_at' => $turn->created_at->copy()->subMinutes(2)])->saveQuietly();

        $response = $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD]);
        $written = Storage::disk('local')->get($response->json('path'));

        $this->assertStringContainsString('"city": "Meteren"', $written, 'the early call fell out of the window');
        $this->assertStringContainsString('HET ECHTE RESULTAAT VAN BEURT EEN', $written);
    }

    /**
     * What the model actually saw, not just what it said it saw. The photos ride
     * along whole while they are still parked — heavy, and that is the right
     * trade: a report you cannot read the photo from is half a report.
     */
    public function test_parked_photos_are_embedded_in_the_report(): void
    {
        Storage::fake('local');

        $user = $this->userWith('assistant.use');
        $this->turn($user->id);

        app(ConversationPhotos::class)->stash(self::THREAD, [
            new Attachment(
                name: 'foto-1',
                media_type: 'image/png',
                base64: 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
            ),
        ]);

        $response = $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD]);
        $written = Storage::disk('local')->get($response->json('path'));

        $this->assertStringContainsString("## Foto's bij dit gesprek", $written);
        $this->assertStringContainsString('data:image/png;base64,iVBORw0KGgo', $written, 'the photo did not ride along');
    }

    /** No parked photos, no section — not an empty heading. */
    public function test_a_conversation_without_photos_has_no_photo_section(): void
    {
        Storage::fake('local');

        $user = $this->userWith('assistant.use');
        $this->turn($user->id);

        $response = $this->actingAs($user)->postJson('/assistant/report', ['conversation' => self::THREAD]);

        $this->assertStringNotContainsString('Foto', Storage::disk('local')->get($response->json('path')));
    }
}

/** Calls one tool, then answers — the smallest conversation that uses a tool. */
class ReportSpyModel implements TalksToModel
{
    private bool $asked = false;

    public function seesImages(): bool
    {
        return true;
    }

    public function readsDocuments(): bool
    {
        return false;
    }

    public function send(array $turns, array $tools, string $system): ModelReply
    {
        $reply = fn (array $texts, array $calls, $stop) => new ModelReply(
            texts: $texts,
            tool_calls: $calls,
            usage: new TokenUsage(1, 1, 0, 0),
            stop_reason: $stop,
            model: 'report-fake',
            raw: null,
        );

        if (!$this->asked) {
            $this->asked = true;

            return $reply([], [new ModelToolCall(
                id: 'toolu_report_fake',
                name: 'find_customer',
                arguments: ['query' => 'label'],
            )], StopReason::wants_tools);
        }

        return $reply(['Gevonden: Majorlabel.'], [], StopReason::finished);
    }
}

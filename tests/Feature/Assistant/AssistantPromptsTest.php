<?php

namespace Tests\Feature\Assistant;

use App\Models\AssistantPrompt;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Questions worth asking again, offered where they make sense.
 *
 * Half of what this assistant can do is invisible from a blank prompt box, so
 * the page somebody is already looking at does the suggesting.
 */
class AssistantPromptsTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    public function test_a_page_only_offers_the_questions_that_belong_on_it(): void
    {
        $user = $this->userWith('assistant.use');

        AssistantPrompt::create(['label' => 'Foto\'s uitlezen', 'question' => 'Kijk naar de foto\'s', 'context' => 'serviceorders.show']);
        AssistantPrompt::create(['label' => 'Wie kan er samen', 'question' => 'Wanneer kunnen twee monteurs', 'context' => 'planner.index']);
        AssistantPrompt::create(['label' => 'Wat kun je?', 'question' => 'Wat kun je allemaal?', 'context' => null]);

        $labels = collect(
            $this->actingAs($user)->getJson('/assistant/prompts?context=serviceorders.show')->json('prompts')
        )->pluck('label');

        $this->assertContains('Foto\'s uitlezen', $labels->all());
        $this->assertContains('Wat kun je?', $labels->all(), 'a question for every page went missing');
        $this->assertNotContains('Wie kan er samen', $labels->all(), 'a planner question showed up on a werkbon');
    }

    public function test_somebody_elses_saved_question_stays_theirs(): void
    {
        $mine = $this->userWith('assistant.use');
        $theirs = $this->userWith('assistant.use');

        AssistantPrompt::create(['user_id' => $theirs->id, 'label' => 'Hun vraag', 'question' => 'Iets', 'context' => null]);

        $labels = collect($this->actingAs($mine)->getJson('/assistant/prompts')->json('prompts'))->pluck('label');

        $this->assertNotContains('Hun vraag', $labels->all());
    }

    public function test_a_question_can_be_saved_and_comes_back_as_mine(): void
    {
        $user = $this->userWith('assistant.use');

        $this->actingAs($user)->postJson('/assistant/prompts', [
            'label' => 'Tijdelijke machines',
            'question' => 'Welke machines staan er nog als onbekend?',
            'context' => 'serviceorders.show',
        ])->assertStatus(201);

        $offered = collect(
            $this->actingAs($user)->getJson('/assistant/prompts?context=serviceorders.show')->json('prompts')
        )->firstWhere('label', 'Tijdelijke machines');

        $this->assertNotNull($offered);
        $this->assertTrue($offered['mine'], 'a saved question came back as unremovable');
    }

    public function test_only_your_own_question_can_be_deleted(): void
    {
        $mine = $this->userWith('assistant.use');
        $theirs = $this->userWith('assistant.use');

        /** Counted against what is already seeded, not against an empty table. */
        $before = AssistantPrompt::count();

        $shipped = AssistantPrompt::create(['label' => 'Van ons', 'question' => 'Iets', 'context' => null]);
        $someone = AssistantPrompt::create(['user_id' => $theirs->id, 'label' => 'Van hun', 'question' => 'Iets', 'context' => null]);
        $own = AssistantPrompt::create(['user_id' => $mine->id, 'label' => 'Van mij', 'question' => 'Iets', 'context' => null]);

        $this->actingAs($mine)->deleteJson('/assistant/prompts/' . $shipped->id)->assertStatus(403);
        $this->actingAs($mine)->deleteJson('/assistant/prompts/' . $someone->id)->assertStatus(403);
        $this->actingAs($mine)->deleteJson('/assistant/prompts/' . $own->id)->assertOk();

        $this->assertSame($before + 2, AssistantPrompt::count(), 'the wrong question was deleted');
    }

    public function test_saving_needs_the_assistant_permission(): void
    {
        $this->actingAs($this->userWith('customer.read'))
            ->postJson('/assistant/prompts', ['label' => 'X', 'question' => 'Iets'])
            ->assertStatus(403);
    }

    /** The shipped list is what makes the box useful on day one. */
    public function test_the_shipped_questions_are_there(): void
    {
        $offered = collect(
            $this->actingAs($this->userWith('assistant.use'))
                ->getJson('/assistant/prompts?context=serviceorders.show')
                ->json('prompts')
        )->pluck('label');

        $this->assertContains('Foto\'s uitlezen', $offered->all());
        $this->assertContains('Tijdelijke machines aanvullen', $offered->all());
    }

    /** The management view shows every question, not just this page's shortlist. */
    public function test_managing_shows_the_questions_of_every_page(): void
    {
        $user = $this->userWith('assistant.use');

        AssistantPrompt::create(['label' => 'Alleen planner', 'question' => 'Iets', 'context' => 'planner.index']);

        $labels = collect($this->actingAs($user)->getJson('/assistant/prompts?context=all')->json('prompts'))
            ->pluck('label');

        $this->assertContains('Alleen planner', $labels->all());
        $this->assertContains('Foto\'s uitlezen', $labels->all());
    }

    public function test_your_own_question_can_be_rewritten(): void
    {
        $user = $this->userWith('assistant.use');
        $prompt = AssistantPrompt::create([
            'user_id' => $user->id,
            'label' => 'Oude naam',
            'question' => 'Oude vraag',
            'context' => null,
        ]);

        $this->actingAs($user)->patchJson('/assistant/prompts/' . $prompt->id, [
            'label' => 'Nieuwe naam',
            'question' => 'Nieuwe vraag',
            'context' => 'assets.show',
        ])->assertOk();

        $prompt->refresh();

        $this->assertSame('Nieuwe naam', $prompt->label);
        $this->assertSame('assets.show', $prompt->context);
    }

    public function test_a_shipped_question_cannot_be_rewritten(): void
    {
        $shipped = AssistantPrompt::create(['label' => 'Van ons', 'question' => 'Iets', 'context' => null]);

        $this->actingAs($this->userWith('assistant.use'))
            ->patchJson('/assistant/prompts/' . $shipped->id, ['label' => 'Gekaapt', 'question' => 'Anders'])
            ->assertStatus(403);

        $this->assertSame('Van ons', $shipped->fresh()->label);
    }
}

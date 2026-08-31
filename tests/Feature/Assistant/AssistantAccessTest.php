<?php

namespace Tests\Feature\Assistant;

use App\Models\Assistant;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The assistant is being trialled by a named handful, so it is the one thing in
 * this application that being an admin does not get you.
 */
class AssistantAccessTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    public function test_an_admin_does_not_get_the_assistant_for_free(): void
    {
        $admin = $this->admin();

        $this->assertTrue($admin->isAdmin());
        $this->assertTrue($admin->hasPermission('assistant.use'), 'admins pass the ordinary check');
        $this->assertFalse(
            $admin->hasExplicitPermission('assistant.use'),
            'but the explicit check is what gates the assistant'
        );
        $this->assertFalse($admin->can('use', Assistant::class));
    }

    public function test_someone_granted_it_may_use_it(): void
    {
        $this->assertTrue($this->userWith('assistant.use')->can('use', Assistant::class));
    }

    public function test_an_ordinary_user_may_not(): void
    {
        $this->assertFalse($this->userWith('serviceorder.read')->can('use', Assistant::class));
    }

    public function test_the_endpoint_refuses_an_admin_without_the_permission(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/assistant/ask', ['question' => 'Hoeveel werkbonnen staan open?'])
            ->assertForbidden();
    }

    public function test_the_endpoint_refuses_someone_not_signed_in(): void
    {
        $this->postJson('/assistant/ask', ['question' => 'Hoi'])->assertStatus(401);
    }

    /**
     * The front end is told the verdict rather than the permissions, so there is
     * only ever one implementation of a rule that has an exception in it.
     */
    public function test_the_page_carries_the_verdict_not_the_reasoning(): void
    {
        $this->actingAs($this->admin())
            ->get('/')
            ->assertInertia(fn ($page) => $page->where('auth.can.use_assistant', false));

        $this->actingAs($this->userWith('assistant.use'))
            ->get('/')
            ->assertInertia(fn ($page) => $page->where('auth.can.use_assistant', true));
    }

    public function test_the_question_is_validated_before_anything_is_spent(): void
    {
        $this->actingAs($this->userWith('assistant.use'))
            ->postJson('/assistant/ask', ['question' => 'x'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('question');
    }

    /**
     * Every call here spends real money at a supplier, and the allowance it eats
     * belongs to the whole company rather than to the person asking. A held key,
     * a retry loop or an impatient double-click should not turn into a bill.
     */
    public function test_asking_over_and_over_is_cut_off(): void
    {
        $user = $this->userWith('assistant.use');

        $responses = collect(range(1, 21))->map(fn () => $this->actingAs($user)
            ->postJson('/assistant/ask', ['question' => 'x'])
            ->getStatusCode());

        $this->assertContains(
            429,
            $responses->all(),
            'the endpoint let twenty-one questions through without throttling',
        );
    }
}

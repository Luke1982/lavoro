<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\AssistantContext;
use App\Models\Activity;
use App\Models\Customer;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Work the assistant does is recorded as the assistant's, while still naming the
 * person who asked for it: they remain accountable and it belongs on their
 * timeline. Only actor_type separates the two.
 */
class AssistantAttributionTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function order(): ServiceOrder
    {
        return ServiceOrder::factory()->create([
            'customer_id' => Customer::factory()->create()->id,
        ]);
    }

    private function latestFor(ServiceOrder $order): Activity
    {
        return Activity::where('subject_type', ServiceOrder::class)
            ->where('subject_id', $order->id)
            ->latest('id')
            ->firstOrFail();
    }

    public function test_a_change_the_assistant_makes_is_recorded_as_machine_made(): void
    {
        $user = $this->admin();
        $order = $this->order();

        app(AssistantContext::class)->run($user, function () use ($order) {
            $order->update(['description' => 'door de assistent']);
        });

        $activity = $this->latestFor($order);

        $this->assertSame('ai', $activity->actor_type);
        $this->assertSame($user->id, $activity->user_id, 'the person who asked must stay accountable');
        $this->assertSame($user->name, $activity->actor_name);
    }

    public function test_an_ordinary_change_is_not_attributed_to_the_assistant(): void
    {
        $user = $this->admin();
        $this->actingAs($user);
        $order = $this->order();

        $order->update(['description' => 'met de hand']);

        $this->assertSame('user', $this->latestFor($order)->actor_type);
    }

    /**
     * Repeated saves of one record inside a request are folded into one entry.
     * That was safe while a request had a single actor; the assistant breaks the
     * assumption, so the actor is part of what may be folded together.
     */
    public function test_a_human_change_and_an_assistant_change_do_not_merge_into_one_entry(): void
    {
        $user = $this->admin();
        $this->actingAs($user);
        $order = $this->order();

        $order->update(['description' => 'met de hand']);
        $by_hand = $this->latestFor($order);

        app(AssistantContext::class)->run($user, function () use ($order) {
            $order->update(['description' => 'door de assistent']);
        });
        $by_assistant = $this->latestFor($order);

        $this->assertNotSame(
            $by_hand->id,
            $by_assistant->id,
            'the assistant\'s change was folded into the entry written for the person'
        );
        $this->assertSame('user', $by_hand->fresh()->actor_type);
        $this->assertSame('ai', $by_assistant->actor_type);
    }

    public function test_the_mark_is_cleared_even_when_the_work_throws(): void
    {
        $context = app(AssistantContext::class);

        try {
            $context->run($this->admin(), fn () => throw new RuntimeException('stuk'));
        } catch (RuntimeException) {
            // expected
        }

        $this->assertFalse($context->isActive(), 'the assistant stayed marked as acting after a failure');
    }

    public function test_nesting_keeps_the_mark_until_the_outermost_call_returns(): void
    {
        $context = app(AssistantContext::class);
        $user = $this->admin();
        $inner_saw = null;

        $context->run($user, function () use ($context, $user, &$inner_saw) {
            $context->run($user, fn () => null);
            $inner_saw = $context->isActive();
        });

        $this->assertTrue($inner_saw, 'a nested call cleared the mark too early');
        $this->assertFalse($context->isActive());
    }
}

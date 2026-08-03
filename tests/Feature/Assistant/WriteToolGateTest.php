<?php

namespace Tests\Feature\Assistant;

use App\Domain\Planning\Clock;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolRegistry;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Event;
use App\Models\Product;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTask;
use App\Models\Ticket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Nothing a model sends may write anything without an approval.
 *
 * The tools enforce this individually and each has its own tests; this one holds
 * the line across all of them at once, including tools written after it. Adding
 * a write tool that forgets the gate makes this go red rather than making it
 * somebody's job to notice.
 *
 * Hostile arguments are the point. A tool may perfectly well refuse a call, and
 * it may perfectly well offer a proposal — what it may not do is act on one.
 */
class WriteToolGateTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    /**
     * The tables a write tool can reach. Counted before and after rather than
     * asserted per tool, because the guarantee is about all of them.
     *
     * @return array<string, int>
     */
    private function tally(): array
    {
        return [
            'events' => Event::count(),
            'service_orders' => ServiceOrder::count(),
            'tickets' => Ticket::count(),
            'tasks' => ServiceOrderTask::count(),
            'activities' => DB::table('activities')->count(),
        ];
    }

    public function test_no_argument_gets_a_write_past_the_gate(): void
    {
        $user = $this->admin();

        $hostile = [
            [],
            ['customer_id' => 1, 'asset_id' => 1, 'service_order_id' => 1],
            ['customer_id' => 'Jansen', 'user_ids' => 'Jeremy'],
            /** Relative, so this case keeps testing the gate rather than decaying
             * into a date the tool refuses for being in the past. */
            [
                'starts_at' => Clock::todayAsDate()->addDay()->toDateString() . ' 08:00',
                'ends_at' => Clock::todayAsDate()->addDay()->toDateString() . ' 10:00',
                'user_ids' => [1],
            ],
            ['subject' => 'Storing', 'description' => 'Doet niets', 'asset_id' => 1],
            ['confirmation_token' => 'ja hoor'],
            ['customer_id' => -1, 'asset_id' => 999999],
        ];

        $writers = collect(app(ToolRegistry::class)->all())
            ->map(fn ($class) => is_string($class) ? app($class) : $class)
            ->filter(fn ($tool) => $tool->requiresConfirmation());

        /**
         * The records those arguments point at are made first, so the count below
         * is of what the tools wrote rather than of what this test set up.
         */
        $plausible = $writers->mapWithKeys(fn ($tool) => [$tool::name() => $this->plausibleFor($tool::name())]);

        $before = $this->tally();
        $gated = 0;

        foreach ($writers as $tool) {
            $gated++;

            /**
             * The perfectly good arguments belong in this sweep more than the bad
             * ones do. Without them the test passes with the gate torn out, because
             * every tool refuses rubbish on its own merits and nothing would have
             * been written either way — proving only that bad input writes nothing.
             */
            foreach ([...$hostile, $plausible[$tool::name()]] as $arguments) {
                $result = app(ToolExecutor::class)->run(new ToolCall(
                    $tool::name(),
                    $arguments,
                    $user,
                    confirmation_token: $arguments['confirmation_token'] ?? null,
                ));

                $this->assertStringNotContainsString(
                    'aangemaakt',
                    (string) $result->summary,
                    $tool::name() . ' reported having done something',
                );
            }
        }

        $this->assertGreaterThan(0, $gated, 'no write tools were found, so nothing was actually tested');
        $this->assertSame($before, $this->tally(), 'something was written without anybody agreeing to it');
    }

    /**
     * The other half of the same guarantee: a proposal must never read as a
     * completed action. "Ik heb de afspraak ingepland" when nothing is planned is
     * the one sentence that costs somebody a morning.
     */
    public function test_a_proposal_says_that_nothing_has_happened_yet(): void
    {
        $user = $this->admin();
        $proposed = 0;

        foreach (app(ToolRegistry::class)->all() as $class) {
            $tool = is_string($class) ? app($class) : $class;

            if (!$tool->requiresConfirmation()) {
                continue;
            }

            $result = app(ToolExecutor::class)->run(new ToolCall(
                $tool::name(),
                $this->plausibleFor($tool::name()),
                $user,
            ));

            /**
             * Skipping a tool that refused would make this test pass by asking
             * nothing of anybody, which is the failure mode a sweeping test has.
             */
            $this->assertFalse(
                $result->is_error,
                $tool::name() . ' refused arguments meant to reach the gate: ' . $result->summary,
            );

            $this->assertIsArray($result->content);
            $proposed++;

            $this->assertSame(
                'bevestiging_nodig',
                $result->content['status'] ?? null,
                $tool::name() . ' answered without asking',
            );

            $this->assertArrayHasKey('confirmation_token', $result->content);
            $this->assertStringContainsString('nog niets gewijzigd', $result->content['note'] ?? '');
        }

        $this->assertSame(4, $proposed, 'a write tool was added or lost without this test noticing');
    }

    /**
     * Arguments good enough to reach the gate, so the assertion above is about the
     * gate rather than about a tool refusing malformed input first.
     *
     * @return array<string, mixed>
     */
    private function plausibleFor(string $tool): array
    {
        $customer = Customer::factory()->create();

        return match ($tool) {
            'create_event' => [
                'starts_at' => Clock::todayAsDate()->addDay()->toDateString() . ' 08:00',
                'ends_at' => Clock::todayAsDate()->addDay()->toDateString() . ' 10:00',
                'user_ids' => [$this->admin()->id],
                'event_type' => 'Onderhoud',
            ],
            'create_ticket' => [
                'asset_id' => Asset::factory()->create([
                    'customer_id' => $customer->id,
                    'product_id' => Product::factory()->create()->id,
                ])->id,
                'subject' => 'Doet niets',
                'description' => 'Hij start niet meer op.',
            ],
            'create_service_order' => ['customer_id' => $customer->id],
            'add_service_order_task' => [
                'service_order_id' => ServiceOrder::factory()->create(['customer_id' => $customer->id])->id,
                'description' => 'Filter vervangen',
            ],
            default => [],
        };
    }
}

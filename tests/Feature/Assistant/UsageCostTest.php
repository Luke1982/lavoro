<?php

namespace Tests\Feature\Assistant;

use Anthropic\Messages\Usage;
use App\Domain\Assistant\AssistantLoop;
use App\Domain\Assistant\UsageCost;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolRegistry;
use App\Models\AssistantUsage;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * What a call costs decides when someone gets cut off, so the arithmetic is
 * worth pinning down rather than trusting.
 */
class UsageCostTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'assistant.eur_per_usd' => 0.5,
            'assistant.pricing.test-model' => [
                'input' => 10.00, 'output' => 100.00, 'cache_write' => 12.50, 'cache_read' => 1.00,
            ],
        ]);
    }

    private function usage(int $input, int $output, ?int $cache_write = null, ?int $cache_read = null): Usage
    {
        return Usage::with(
            cacheCreation: null,
            cacheCreationInputTokens: $cache_write,
            cacheReadInputTokens: $cache_read,
            inferenceGeo: null,
            inputTokens: $input,
            outputTokens: $output,
            outputTokensDetails: null,
            serverToolUse: null,
            serviceTier: null,
        );
    }

    public function test_a_call_is_priced_per_million_tokens_and_converted_to_euros(): void
    {
        $cost = UsageCost::forCall('test-model', $this->usage(1_000_000, 100_000));

        // 1M in at $10 + 100k out at $100 = $10 + $10 = $20, at 0.5 = €10
        $this->assertSame(20_000_000, $cost->cost_usd_micros);
        $this->assertSame(10_000_000, $cost->cost_micros);
        $this->assertSame(10.0, $cost->euros());
    }

    /**
     * A call costs a fraction of a cent. Rounding a row to whole cents would
     * throw most of it away, so the meter counts in millionths of a euro.
     */
    public function test_a_cost_far_below_one_cent_survives_instead_of_rounding_to_nothing(): void
    {
        // One token is worth a hundred-thousandth of a euro at these rates.
        // Counting in cents would round it away; counting in micros keeps it.
        $cost = UsageCost::forCall('test-model', $this->usage(1, 0));

        $this->assertGreaterThan(0, $cost->cost_micros, 'a tiny cost was rounded away to nothing');
        $this->assertLessThan(10_000, $cost->cost_micros, 'and it is still far below one cent');
    }

    /**
     * input_tokens is only the part of the prompt that was not cached, and the
     * cached parts carry their own rates. Pricing the first alone would
     * under-count the moment caching is switched on.
     */
    public function test_cached_tokens_are_priced_separately_from_fresh_input(): void
    {
        $without = UsageCost::forCall('test-model', $this->usage(1_000_000, 0));
        $with_read = UsageCost::forCall('test-model', $this->usage(0, 0, cache_read: 1_000_000));
        $with_write = UsageCost::forCall('test-model', $this->usage(0, 0, cache_write: 1_000_000));

        $this->assertSame(10_000_000, $without->cost_usd_micros);
        $this->assertSame(1_000_000, $with_read->cost_usd_micros, 'reading cache should cost a tenth');
        $this->assertSame(12_500_000, $with_write->cost_usd_micros, 'writing cache should cost a quarter more');
    }

    public function test_the_four_token_counts_are_all_carried_through(): void
    {
        $cost = UsageCost::forCall('test-model', $this->usage(10, 20, cache_write: 30, cache_read: 40));

        $this->assertSame(10, $cost->input_tokens);
        $this->assertSame(20, $cost->output_tokens);
        $this->assertSame(30, $cost->cache_write_tokens);
        $this->assertSame(40, $cost->cache_read_tokens);
    }

    /**
     * A model nobody priced is counted as nothing and says so, rather than being
     * guessed at. A wrong number that looks right is worse than a nought.
     */
    public function test_an_unpriced_model_costs_nothing_and_admits_it(): void
    {
        $cost = UsageCost::forCall('model-we-never-priced', $this->usage(1_000_000, 1_000_000));

        $this->assertSame(0, $cost->cost_micros);
        $this->assertFalse($cost->isPriced());
    }

    public function test_the_rate_used_is_kept_so_a_currency_move_cannot_reprice_the_past(): void
    {
        $before = UsageCost::forCall('test-model', $this->usage(1_000_000, 0));

        config(['assistant.eur_per_usd' => 0.9]);
        $after = UsageCost::forCall('test-model', $this->usage(1_000_000, 0));

        $this->assertSame(0.5, $before->eur_per_usd);
        $this->assertSame(0.9, $after->eur_per_usd);
        $this->assertNotSame($before->cost_micros, $after->cost_micros);
    }

    public function test_monthly_spend_adds_up_every_call_from_everyone(): void
    {
        $one = $this->admin();
        $two = $this->userWith('serviceorder.read');

        foreach ([[$one, 1_500_000], [$two, 2_500_000], [$one, 1_000_000]] as [$user, $micros]) {
            AssistantUsage::create([
                'user_id' => $user->id, 'model' => 'test-model',
                'input_tokens' => 1, 'output_tokens' => 1,
                'cost_micros' => $micros, 'cost_usd_micros' => $micros, 'eur_per_usd' => 0.5,
            ]);
        }

        $this->assertSame(5_000_000, AssistantUsage::spentMicrosInMonth());
        $this->assertSame(3, AssistantUsage::inMonth(now())->count());
    }

    public function test_last_months_spend_does_not_count_against_this_month(): void
    {
        AssistantUsage::create([
            'user_id' => $this->admin()->id, 'model' => 'test-model',
            'input_tokens' => 1, 'output_tokens' => 1,
            'cost_micros' => 9_000_000, 'cost_usd_micros' => 9_000_000, 'eur_per_usd' => 0.5,
        ])->forceFill(['created_at' => now()->subMonthNoOverflow()->startOfMonth()])->save();

        $this->assertSame(0, AssistantUsage::spentMicrosInMonth());
    }

    public function test_a_question_records_a_row_per_api_call_not_per_question(): void
    {
        Customer::factory()->create(['name' => 'Prins']);

        $model = new FakeModel([
            FakeModel::callsTool('find_customer', ['query' => 'Prins']),
            FakeModel::says('Gevonden.'),
        ]);

        (new AssistantLoop(
            $model,
            app(ToolRegistry::class),
            app(ToolExecutor::class),
        ))->ask($this->admin(), 'Ken je Prins?', 'systeem');

        $this->assertSame(2, AssistantUsage::count(), 'two calls to the model, two rows');
    }
}

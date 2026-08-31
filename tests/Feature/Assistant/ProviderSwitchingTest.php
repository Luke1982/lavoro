<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\Contracts\AssistantTurn;
use App\Domain\Assistant\Contracts\ModelFailure;
use App\Domain\Assistant\Contracts\ModelToolResult;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\ToolResultsTurn;
use App\Domain\Assistant\Contracts\UserTurn;
use App\Domain\Assistant\ModelPicker;
use App\Domain\Assistant\Pricing;
use App\Domain\Assistant\Providers\AnthropicModel;
use App\Domain\Assistant\Providers\OpenAiCompatibleModel;
use App\Domain\Tools\ToolRegistry;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * Changing supplier is meant to be one config value. These hold that to it.
 *
 * The switch broke once already in a way nothing would have caught: the
 * resolver compared against OpenAiCompatibleModel::class without importing it,
 * so the name quietly resolved inside App\Providers instead, never matched, and
 * every OpenAI-compatible supplier fell through to a constructor it could not
 * fill. `::class` does not check that the class exists.
 */
class ProviderSwitchingTest extends TestCase
{
    use CreatesAuthenticatedUsers;

    /** @return array<string, array{0: string, 1: class-string}> */
    public static function providers(): array
    {
        return [
            'anthropic' => ['anthropic', AnthropicModel::class],
            'deepseek' => ['deepseek', OpenAiCompatibleModel::class],
            'mistral' => ['mistral', OpenAiCompatibleModel::class],
            'qwen' => ['qwen', OpenAiCompatibleModel::class],
            'moonshot' => ['moonshot', OpenAiCompatibleModel::class],
            'openai' => ['openai', OpenAiCompatibleModel::class],
        ];
    }

    #[DataProvider('providers')]
    public function test_every_configured_provider_resolves(string $provider, string $expected): void
    {
        config(['assistant.provider' => $provider]);

        $this->assertInstanceOf($expected, app(TalksToModel::class));
    }

    public function test_an_unknown_provider_says_so_instead_of_failing_obscurely(): void
    {
        config(['assistant.provider' => 'does-not-exist']);

        $this->expectException(ModelUnavailable::class);

        app(TalksToModel::class);
    }

    public function test_every_provider_in_the_config_has_a_driver_and_a_model(): void
    {
        foreach (config('assistant.providers') as $name => $settings) {
            $this->assertArrayHasKey('driver', $settings, $name . ' has no driver');
            $this->assertArrayHasKey('model', $settings, $name . ' has no model');

            if ($settings['driver'] === OpenAiCompatibleModel::class) {
                $this->assertArrayHasKey('base_url', $settings, $name . ' has no base_url');
            }
        }
    }

    /**
     * A conversation is answered by the cheapest model equal to the hardest tool
     * the person can reach for, so someone who only gets lookups is not paying
     * for reasoning they can never invoke.
     */
    /**
     * The dearest model somebody can be given is decided by the hardest tool they
     * can reach, not by the question they happen to ask.
     *
     * A technician used to sit below a planner here. Diagnosing a fault against
     * the history of a product and its documentation changed that, and rightly:
     * it is the hardest reasoning in this application and it is monteurs who do
     * it.
     *
     * What is left is only an ordering — more tools never means a cheaper model.
     * The rungs themselves have gone flat, because a tool rated 8 that everybody
     * can reach puts everybody on the model for it, whatever they asked. Tiering
     * by what somebody *could* do stops sorting anyone once one hard thing is
     * universal; sorting by what was actually asked is a different mechanism,
     * and not one that can be bolted on here.
     */
    public function test_the_hardest_tool_offered_sets_the_difficulty(): void
    {
        $registry = app(ToolRegistry::class);

        $technician = $this->userWith('serviceorder.read_own');
        $planner = $this->userWithPermissions('serviceorder.read', 'event.see_all', 'event.create');
        $nobody = User::factory()->create();

        $this->assertGreaterThanOrEqual(
            $registry->requiredDifficultyFor($nobody),
            $registry->requiredDifficultyFor($technician),
            'more tools can never mean a cheaper model'
        );

        $this->assertGreaterThanOrEqual(
            $registry->requiredDifficultyFor($technician),
            $registry->requiredDifficultyFor($planner),
            'a planner reaches everything a technician does, and the planning tools besides'
        );
    }

    /**
     * Every model that can be sent has to have a price against exactly the id that
     * gets sent.
     *
     * A mismatch fails twice and silently. The picker sorts an unpriced model last,
     * so it is never chosen however cheap it really is — and if it ever were, its
     * usage would be recorded at nought and never show up against anybody's
     * allowance. Found the hard way: a haiku entry priced under a family name
     * while the id carried a date.
     */
    public function test_every_configured_model_has_a_price_under_its_own_id(): void
    {
        $unpriced = [];

        foreach (config('assistant.providers') as $name => $settings) {
            $model = $settings['model'] ?? null;

            if ($model !== null && Pricing::forModel($model) === null) {
                $unpriced[] = $name . ' (' . $model . ')';
            }
        }

        $this->assertSame(
            [],
            $unpriced,
            'these would never be chosen and would cost nothing on paper: ' . implode(', ', $unpriced),
        );
    }

    /**
     * Sorting questions buys nothing unless somewhere cheaper exists to send the
     * easy ones, which is the whole reason the sorting was built.
     */
    public function test_an_easy_question_lands_somewhere_cheaper_than_a_hard_one(): void
    {
        $picker = app(ModelPicker::class);

        $cheap = config('assistant.providers.' . $picker->providerFor(2) . '.model');
        $dear = config('assistant.providers.' . $picker->providerFor(9) . '.model');

        $this->assertNotSame($dear, $cheap, 'every question still goes to the same model');
        $this->assertLessThan(
            Pricing::forModel($dear)['output'],
            Pricing::forModel($cheap)['output'],
            'the model chosen for a lookup is not actually cheaper'
        );
    }

    public function test_every_tool_rates_itself_somewhere_on_the_scale(): void
    {
        foreach (app(ToolRegistry::class)->all() as $tool) {
            $difficulty = $tool::difficulty();

            $this->assertGreaterThanOrEqual(1, $difficulty, $tool::name() . ' is rated below the scale');
            $this->assertLessThanOrEqual(10, $difficulty, $tool::name() . ' is rated above the scale');
        }
    }

    public function test_the_cheapest_model_that_clears_the_bar_is_chosen(): void
    {
        config([
            'assistant.providers.deepseek.api_key' => 'x',
            'assistant.providers.anthropic.api_key' => 'x',
        ]);

        $picker = new ModelPicker;

        $this->assertSame('deepseek', $picker->providerFor(5), 'deepseek clears 5 and is far cheaper');
        $this->assertSame('anthropic', $picker->providerFor(8), 'deepseek is rated 7, so 8 needs anthropic');
    }

    /**
     * A model nobody can call is not a candidate, however well it scores.
     */
    /**
     * Which suppliers exist is decided by the test, not by whoever's .env this runs
     * on. Left to the environment these assertions pass or fail depending on which
     * keys a developer happens to have — and they quietly started failing the day a
     * real deepseek key was added, which is not the behaviour of a test.
     *
     * @param  array<string, int|null>  $providers  Name to capability; a key is given to each.
     */
    private function onlyProviders(array $providers): void
    {
        foreach (array_keys(config('assistant.providers')) as $name) {
            config(['assistant.providers.' . $name . '.api_key' => null]);
        }

        foreach ($providers as $name => $capability) {
            config(['assistant.providers.' . $name . '.api_key' => 'aanwezig']);

            if ($capability !== null) {
                config(['assistant.providers.' . $name . '.capability' => $capability]);
            }
        }
    }

    public function test_a_provider_without_a_key_is_never_chosen(): void
    {
        $this->onlyProviders(['anthropic' => null]);

        $this->assertSame('anthropic', (new ModelPicker)->providerFor(3));
    }

    /**
     * An unpriced model sorts last rather than first, so a missing price is
     * never read as "free" and does not quietly win every comparison.
     */
    public function test_a_model_with_no_price_does_not_undercut_everything(): void
    {
        $this->onlyProviders(['moonshot' => null, 'anthropic' => null]);
        config(['assistant.pricing' => collect(config('assistant.pricing'))
            ->reject(fn ($rates, $model) => $model === 'kimi-k2-0711-preview')
            ->all()]);

        $this->assertSame('anthropic', (new ModelPicker)->providerFor(7));
    }

    public function test_nothing_clever_enough_still_answers_rather_than_failing(): void
    {
        $this->onlyProviders(['deepseek' => null]);

        $this->assertSame('deepseek', (new ModelPicker)->providerFor(10));
    }

    /**
     * The three things OpenAI's shape does differently, all of which would
     * silently produce a broken conversation rather than an error.
     */
    public function test_the_openai_shape_is_translated_in_both_directions(): void
    {
        Http::fake([
            '*' => Http::response([
                'model' => 'deepseek-chat',
                'choices' => [[
                    'finish_reason' => 'tool_calls',
                    'message' => [
                        'role' => 'assistant',
                        'content' => null,
                        'tool_calls' => [[
                            'id' => 'call_1',
                            'type' => 'function',
                            'function' => ['name' => 'find_customer', 'arguments' => '{"query":"Prins"}'],
                        ]],
                    ],
                ]],
                'usage' => [
                    'prompt_tokens' => 1000,
                    'completion_tokens' => 50,
                    'prompt_tokens_details' => ['cached_tokens' => 400],
                ],
            ]),
        ]);

        $model = new OpenAiCompatibleModel('https://example.test/v1', 'key', 'deepseek-chat', 4096);

        $reply = $model->send(
            turns: [
                new UserTurn(['Wie is Prins?']),
                new AssistantTurn(['role' => 'assistant', 'content' => 'even kijken']),
                new ToolResultsTurn([
                    new ModelToolResult('call_a', '{"ok":1}'),
                    new ModelToolResult('call_b', 'mislukt', is_error: true),
                ]),
            ],
            tools: [[
                'name' => 'find_customer',
                'description' => 'Zoekt klanten.',
                'input_schema' => ['type' => 'object', 'properties' => []],
                'strict' => true,
            ]],
            system: 'systeem',
        );

        // Arguments arrive as a JSON string and must come back as an array.
        $this->assertSame(['query' => 'Prins'], $reply->tool_calls[0]->arguments);
        $this->assertSame(StopReason::wants_tools, $reply->stop_reason);

        // prompt_tokens includes the cached ones, so they are taken back out.
        $this->assertSame(600, $reply->usage->input);
        $this->assertSame(400, $reply->usage->cache_read);
        $this->assertSame(50, $reply->usage->output);

        Http::assertSent(function ($request) {
            $body = $request->data();

            // The system prompt is a message here, not a separate field.
            $this->assertSame('system', $body['messages'][0]['role']);

            // Each tool result is its own message, naming the call it answers.
            $this->assertSame('tool', $body['messages'][3]['role']);
            $this->assertSame('call_a', $body['messages'][3]['tool_call_id']);
            $this->assertSame('tool', $body['messages'][4]['role']);
            $this->assertSame('call_b', $body['messages'][4]['tool_call_id']);

            // Tools are wrapped as functions, with the schema under parameters.
            $this->assertSame('function', $body['tools'][0]['type']);
            $this->assertSame('find_customer', $body['tools'][0]['function']['name']);
            $this->assertArrayHasKey('parameters', $body['tools'][0]['function']);

            return true;
        });
    }

    public function test_an_empty_balance_is_reported_as_such_whatever_the_wording(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'Insufficient Balance']], 400)]);

        $model = new OpenAiCompatibleModel('https://example.test/v1', 'key', 'deepseek-chat', 4096);

        try {
            $model->send([new UserTurn(['hoi'])], [], 'systeem');
            $this->fail('an empty balance should have been reported');
        } catch (ModelUnavailable $e) {
            $this->assertSame(ModelFailure::no_credit, $e->reason);
        }
    }

    public function test_a_rejected_key_is_reported_as_a_key_problem(): void
    {
        Http::fake(['*' => Http::response(['error' => ['message' => 'nope']], 401)]);

        $model = new OpenAiCompatibleModel('https://example.test/v1', 'key', 'deepseek-chat', 4096);

        try {
            $model->send([new UserTurn(['hoi'])], [], 'systeem');
            $this->fail('a rejected key should have been reported');
        } catch (ModelUnavailable $e) {
            $this->assertSame(ModelFailure::bad_credentials, $e->reason);
        }
    }
}

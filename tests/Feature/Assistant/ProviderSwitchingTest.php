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
use App\Domain\Assistant\Providers\AnthropicModel;
use App\Domain\Assistant\Providers\OpenAiCompatibleModel;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
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

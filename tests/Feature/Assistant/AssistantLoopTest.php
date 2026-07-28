<?php

namespace Tests\Feature\Assistant;

use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\ToolUseBlock;
use Anthropic\Messages\Usage;
use App\Domain\Assistant\AssistantLoop;
use App\Domain\Assistant\TalksToModel;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolRegistry;
use App\Models\AssistantToolCall;
use App\Models\Customer;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\CreatesAuthenticatedUsers;
use Tests\TestCase;

/**
 * The loop between the model and the tools, driven by canned replies.
 *
 * Everything here would otherwise only be provable by spending money on a live
 * conversation, and the parts most likely to be wrong — what goes back into the
 * history, whether every call gets answered, when to stop — are ours, not the
 * model's.
 */
class AssistantLoopTest extends TestCase
{
    use CreatesAuthenticatedUsers;
    use RefreshDatabase;

    private function loop(FakeModel $model): AssistantLoop
    {
        return new AssistantLoop(
            $model,
            app(ToolRegistry::class),
            app(ToolExecutor::class),
        );
    }

    public function test_a_plain_answer_comes_back_without_touching_a_tool(): void
    {
        $model = new FakeModel([FakeModel::says('Goedemiddag.')]);

        $answer = $this->loop($model)->ask($this->admin(), 'Hallo', 'systeem');

        $this->assertSame('Goedemiddag.', $answer->text);
        $this->assertSame(0, $answer->tool_rounds);
        $this->assertSame(0, AssistantToolCall::count());
    }

    public function test_a_tool_the_model_asks_for_is_run_and_its_answer_returned(): void
    {
        $customer = Customer::factory()->create(['name' => 'Prins Klimaatservice']);

        $model = new FakeModel([
            FakeModel::callsTool('find_customer', ['query' => 'Prins']),
            FakeModel::says('Prins Klimaatservice staat in het systeem.'),
        ]);

        $answer = $this->loop($model)->ask($this->admin(), 'Ken je Prins?', 'systeem');

        $this->assertSame(1, $answer->tool_rounds);
        $this->assertStringContainsString('Prins', $answer->text);
        $this->assertSame('find_customer', AssistantToolCall::sole()->tool);
        $this->assertStringContainsString($customer->name, $model->lastToolResultText());
    }

    /**
     * The model's own turn goes back verbatim. Rebuilding it from the text alone
     * would drop the tool_use block the result is answering, and the next request
     * would be rejected as an unanswered call.
     */
    public function test_the_models_turn_is_handed_back_unchanged(): void
    {
        $model = new FakeModel([
            FakeModel::callsTool('find_customer', ['query' => 'aa']),
            FakeModel::says('Klaar.'),
        ]);

        $this->loop($model)->ask($this->admin(), 'Zoek', 'systeem');

        $assistant_turn = $model->sent[1][1];

        $this->assertSame('assistant', $assistant_turn['role']);
        $this->assertInstanceOf(ToolUseBlock::class, $assistant_turn['content'][0]);
    }

    /**
     * Two calls in one turn must be answered in one message. Splitting them
     * teaches the model to stop asking for more than one thing at a time.
     */
    public function test_every_call_in_a_turn_is_answered_in_a_single_message(): void
    {
        $model = new FakeModel([
            FakeModel::callsTools([
                ['find_customer', ['query' => 'aa']],
                ['search_service_orders', ['limit' => 2]],
            ]),
            FakeModel::says('Klaar.'),
        ]);

        $this->loop($model)->ask($this->admin(), 'Twee dingen', 'systeem');

        $results_turn = $model->sent[1][2];

        $this->assertSame('user', $results_turn['role']);
        $this->assertCount(2, $results_turn['content']);
        $this->assertSame(2, AssistantToolCall::count());
    }

    /**
     * A refused tool is still a result. Leaving it out would strand the
     * conversation on a call that never gets an answer.
     */
    public function test_a_refused_tool_still_gets_a_result_marked_as_an_error(): void
    {
        $model = new FakeModel([
            FakeModel::callsTool('find_customer', ['query' => 'Prins']),
            FakeModel::says('Dat mag ik niet zien.'),
        ]);

        $this->loop($model)->ask($this->userWith('serviceorder.read_own'), 'Zoek', 'systeem');

        $result = $model->sent[1][2]['content'][0];

        $this->assertTrue($result['isError']);
        $this->assertSame('denied', AssistantToolCall::sole()->outcome);
    }

    public function test_a_model_that_never_stops_asking_is_cut_off(): void
    {
        $model = new FakeModel(array_fill(0, 10, FakeModel::callsTool('find_customer', ['query' => 'aa'])));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/tool-rondes/');

        $this->loop($model)->ask($this->admin(), 'Blijf zoeken', 'systeem', max_rounds: 3);
    }

    public function test_a_refusal_is_reported_rather_than_returned_as_an_answer(): void
    {
        $model = new FakeModel([FakeModel::refuses()]);

        $this->expectException(RuntimeException::class);

        $this->loop($model)->ask($this->admin(), 'Iets naars', 'systeem');
    }

    public function test_the_tools_offered_are_the_ones_this_user_is_given(): void
    {
        $model = new FakeModel([FakeModel::says('Hoi.')]);
        $user = $this->admin();

        $this->loop($model)->ask($user, 'Hallo', 'systeem');

        $offered = array_map(fn ($tool) => $tool->name, $model->sent[0][0]);

        $this->assertSame(
            array_column(app(ToolRegistry::class)->definitionsFor($user), 'name'),
            $offered,
        );
    }

    public function test_it_survives_a_werkbon_question_end_to_end(): void
    {
        $user = $this->userWith('serviceorder.read');
        ServiceOrder::factory()->create(['customer_id' => Customer::factory()->create()->id]);

        $model = new FakeModel([
            FakeModel::callsTool('search_service_orders', ['only_open' => true, 'limit' => 5]),
            FakeModel::says('Er staat er nog één open.'),
        ]);

        $answer = $this->loop($model)->ask($user, 'Wat staat er open?', 'systeem');

        $this->assertSame(1, $answer->tool_rounds);
        $this->assertStringContainsString('service_orders', $model->lastToolResultText());
    }
}

/**
 * Replies the model would have given, handed out in order. Records every request
 * so a test can look at what the loop actually sent.
 */
class FakeModel implements TalksToModel
{
    /** @var array<int, array{0: array<int, mixed>, 1: mixed, 2: mixed}> */
    public array $sent = [];

    private int $turn = 0;

    /** @param array<int, Message> $replies */
    public function __construct(private readonly array $replies) {}

    public function send(array $messages, array $tools, string $system): Message
    {
        $this->sent[] = [$tools, ...array_slice($messages, -2)];

        return $this->replies[$this->turn++]
            ?? throw new RuntimeException('de nepmodel-antwoorden zijn op');
    }

    public function lastToolResultText(): string
    {
        foreach (array_reverse($this->sent) as $request) {
            foreach ($request as $entry) {
                if (is_array($entry) && ($entry['role'] ?? null) === 'user' && is_array($entry['content'])) {
                    $first = $entry['content'][0];

                    if (!is_string($first) && isset($first['content'])) {
                        return (string) $first['content'];
                    }
                }
            }
        }

        return '';
    }

    public static function says(string $text): Message
    {
        return self::message([TextBlock::with(citations: null, text: $text)], 'end_turn');
    }

    public static function callsTool(string $name, array $input): Message
    {
        return self::callsTools([[$name, $input]]);
    }

    /** @param array<int, array{0: string, 1: array<string, mixed>}> $calls */
    public static function callsTools(array $calls): Message
    {
        $blocks = [];

        foreach ($calls as $index => [$name, $input]) {
            $blocks[] = ToolUseBlock::with(
                id: 'toolu_fake_' . $index . '_' . $name,
                input: $input,
                name: $name,
            );
        }

        return self::message($blocks, 'tool_use');
    }

    public static function refuses(): Message
    {
        return self::message([], 'refusal');
    }

    /** @param array<int, mixed> $content */
    private static function message(array $content, string $stop_reason): Message
    {
        return Message::with(
            id: 'msg_fake',
            container: null,
            content: $content,
            model: 'claude-opus-5',
            stopDetails: null,
            stopReason: $stop_reason,
            stopSequence: null,
            usage: Usage::with(
                cacheCreation: null,
                cacheCreationInputTokens: null,
                cacheReadInputTokens: null,
                inferenceGeo: null,
                inputTokens: 10,
                outputTokens: 5,
                outputTokensDetails: null,
                serverToolUse: null,
                serviceTier: null,
            ),
        );
    }
}

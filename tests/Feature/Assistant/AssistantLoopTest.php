<?php

namespace Tests\Feature\Assistant;

use App\Domain\Assistant\AssistantLoop;
use App\Domain\Assistant\Contracts\AssistantTurn;
use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\ModelToolCall;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\Contracts\ToolResultsTurn;
use App\Domain\Assistant\Contracts\UserTurn;
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

        $assistant_turn = $model->turnsOn(1)[1];

        $this->assertInstanceOf(AssistantTurn::class, $assistant_turn);
        $this->assertSame($model->sent[0] ? 'fake-assistant-turn' : null, $assistant_turn->raw[0]);
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

        $results_turn = $model->turnsOn(1)[2];

        $this->assertInstanceOf(ToolResultsTurn::class, $results_turn);
        $this->assertCount(2, $results_turn->results);
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

        $result = $model->turnsOn(1)[2]->results[0];

        $this->assertTrue($result->is_error);
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

        $offered = array_column($model->sent[0]['tools'], 'name');

        $this->assertSame(
            array_column(app(ToolRegistry::class)->definitionsFor($user), 'name'),
            $offered,
        );
    }

    /**
     * Nothing may fall out of the history between rounds. A model that has lost
     * the original question answers a different one, and does it confidently.
     */
    public function test_the_whole_conversation_is_resent_every_round(): void
    {
        $model = new FakeModel([
            FakeModel::callsTool('find_customer', ['query' => 'aa']),
            FakeModel::callsTool('search_service_orders', ['limit' => 1]),
            FakeModel::says('Klaar.'),
        ]);

        $this->loop($model)->ask($this->admin(), 'De oorspronkelijke vraag', 'systeem');

        $this->assertCount(1, $model->turnsOn(0));
        $this->assertCount(3, $model->turnsOn(1));
        $this->assertCount(5, $model->turnsOn(2));

        foreach ([0, 1, 2] as $round) {
            $opening = $model->turnsOn($round)[0];

            $this->assertInstanceOf(UserTurn::class, $opening);
            $this->assertSame(
                'De oorspronkelijke vraag',
                $opening->texts[0],
                'the original question was lost by round ' . $round
            );
        }
    }

    /**
     * Half a list of werkbonnen reads exactly like a whole one, so a turn that
     * ran out of room must not be handed over as an answer.
     */
    public function test_a_truncated_answer_is_refused_rather_than_returned(): void
    {
        $model = new FakeModel([FakeModel::ranOutOfRoom('De openstaande werkbonnen zijn: 1, 2,')]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/afgekapt/');

        $this->loop($model)->ask($this->admin(), 'Wat staat er open?', 'systeem');
    }

    /**
     * Strict validation is what lets a tool trust the shape of its arguments
     * without re-checking them. Sending the definitions without it would quietly
     * move that burden back onto every tool.
     */
    public function test_the_tools_are_sent_with_strict_validation_on(): void
    {
        $model = new FakeModel([FakeModel::says('Hoi.')]);

        $this->loop($model)->ask($this->admin(), 'Hallo', 'systeem');

        foreach ($model->sent[0]['tools'] as $tool) {
            $this->assertTrue($tool['strict'], $tool['name'] . ' is sent without strict validation');
        }
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
 *
 * Note how little there is to it now: no Message, no Usage, no content blocks.
 * A fake that has to construct a supplier's own types is a fake that stops
 * compiling when that supplier changes theirs.
 */
class FakeModel implements TalksToModel
{
    /**
     * The whole request is kept, not a slice of it. Keeping only the tail would
     * mean a loop that quietly forgot the earlier conversation still passed.
     *
     * @var array<int, array{tools: array<int, mixed>, turns: array<int, mixed>, system: string}>
     */
    public array $sent = [];

    private int $turn = 0;

    /** @param array<int, ModelReply> $replies */
    public function __construct(private readonly array $replies) {}

    public function send(array $turns, array $tools, string $system): ModelReply
    {
        $this->sent[] = ['tools' => $tools, 'turns' => $turns, 'system' => $system];

        return $this->replies[$this->turn++]
            ?? throw new RuntimeException('de nepmodel-antwoorden zijn op');
    }

    /** @return array<int, mixed> */
    public function turnsOn(int $request): array
    {
        return $this->sent[$request]['turns'];
    }

    public function lastToolResultText(): string
    {
        foreach (array_reverse($this->sent) as $request) {
            foreach (array_reverse($request['turns']) as $turn) {
                if ($turn instanceof ToolResultsTurn) {
                    return $turn->results[0]->content;
                }
            }
        }

        return '';
    }

    public static function says(string $text): ModelReply
    {
        return self::reply([$text], [], StopReason::finished);
    }

    /** @param array<string, mixed> $input */
    public static function callsTool(string $name, array $input): ModelReply
    {
        return self::callsTools([[$name, $input]]);
    }

    /** @param array<int, array{0: string, 1: array<string, mixed>}> $calls */
    public static function callsTools(array $calls): ModelReply
    {
        $blocks = [];

        foreach ($calls as $index => [$name, $input]) {
            $blocks[] = new ModelToolCall(
                id: 'toolu_fake_' . $index . '_' . $name,
                name: $name,
                arguments: $input,
            );
        }

        return self::reply([], $blocks, StopReason::wants_tools);
    }

    public static function refuses(): ModelReply
    {
        return self::reply([], [], StopReason::refused);
    }

    public static function ranOutOfRoom(string $partial): ModelReply
    {
        return self::reply([$partial], [], StopReason::out_of_room);
    }

    /**
     * @param  array<int, string>  $texts
     * @param  array<int, ModelToolCall>  $calls
     */
    private static function reply(array $texts, array $calls, StopReason $stop_reason): ModelReply
    {
        return new ModelReply(
            texts: $texts,
            tool_calls: $calls,
            usage: new TokenUsage(input: 10, output: 5),
            stop_reason: $stop_reason,
            model: 'test-model',
            raw: ['fake-assistant-turn', ...array_map(fn ($c) => $c->id, $calls)],
        );
    }
}

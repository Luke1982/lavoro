<?php

namespace App\Domain\Assistant;

use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolResultBlockParam;
use Anthropic\Messages\ToolUseBlock;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolRegistry;
use App\Models\AssistantUsage;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Runs one question to its answer: ask the model, run whatever tools it asks
 * for, hand the results back, repeat until it stops asking.
 *
 * The loop owns the two things that are easy to get wrong. The assistant's own
 * turn must go back into the history exactly as it arrived, thinking blocks
 * included, or the next request is rejected. And every tool call must be
 * answered, in one message, even the ones that failed — a missing result leaves
 * the conversation permanently unanswerable.
 */
class AssistantLoop
{
    public function __construct(
        private readonly TalksToModel $model,
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $executor,
    ) {}

    /**
     * @param  Closure(string):void|null  $onText  Called with each sentence the model writes.
     * @param  Closure(string, array, bool):void|null  $onTool  Called with name, arguments and whether it failed.
     */
    public function ask(
        User $user,
        string $question,
        string $system,
        int $max_rounds = 6,
        ?Closure $onText = null,
        ?Closure $onTool = null,
    ): AssistantAnswer {
        $tools = $this->definitions($user);
        $messages = [['role' => 'user', 'content' => $question]];
        $rounds = 0;
        $spoken = [];
        $spent = 0;

        while (true) {
            $response = $this->model->send($messages, $tools, $system);

            $spent += $this->recordCost($user, $response);

            if ($response->stopReason === 'refusal') {
                throw new RuntimeException('Het model heeft de vraag geweigerd.');
            }

            /**
             * A turn that ran out of room is not an answer, and it is the one
             * failure that looks like success: half a list of werkbonnen reads
             * exactly like a whole one. Better to say nothing than to let someone
             * act on it. Thinking counts against the same budget, so the fix is
             * usually more room rather than a shorter question.
             */
            if ($response->stopReason === 'max_tokens') {
                throw new RuntimeException(
                    'Het antwoord paste niet binnen max_tokens en is afgekapt. '
                    . 'Verhoog ASSISTANT_MAX_TOKENS (nu ' . config('assistant.max_tokens') . ').'
                );
            }

            foreach ($this->spokenText($response) as $text) {
                $spoken[] = $text;
                $onText && $onText($text);
            }

            $calls = array_values(array_filter(
                $response->content,
                fn ($block) => $block instanceof ToolUseBlock,
            ));

            if ($calls === []) {
                return new AssistantAnswer(implode("\n", $spoken), $rounds, $response, $spent);
            }

            if (++$rounds > $max_rounds) {
                throw new RuntimeException('Gestopt na ' . $max_rounds . ' tool-rondes zonder antwoord.');
            }

            $messages[] = ['role' => 'assistant', 'content' => $response->content];
            $messages[] = ['role' => 'user', 'content' => $this->run($calls, $user, $onTool)];
        }
    }

    /**
     * @param  array<int, ToolUseBlock>  $calls
     * @return array<int, ToolResultBlockParam>
     */
    private function run(array $calls, User $user, ?Closure $onTool): array
    {
        $results = [];

        foreach ($calls as $block) {
            $result = $this->executor->run(new ToolCall(
                name: $block->name,
                arguments: $block->input,
                user: $user,
                external_id: $block->id,
            ));

            $onTool && $onTool($block->name, $block->input, $result->is_error);

            $results[] = ToolResultBlockParam::with(
                toolUseID: $block->id,
                content: $result->toModelContent(),
                isError: $result->is_error,
            );
        }

        return $results;
    }

    /**
     * Writes down what the call cost and returns it, so the caller can show the
     * price of one question without adding the rows back up.
     *
     * A failed write must not lose the answer someone is waiting for, so it is
     * reported and swallowed — the same position the activity log takes. It does
     * mean the meter can undercount when the database is unhappy, which is the
     * right way round: an unbilled call beats a lost answer.
     */
    private function recordCost(User $user, Message $response): int
    {
        $cost = UsageCost::forCall((string) $response->model, $response->usage);

        try {
            AssistantUsage::create([
                'user_id' => $user->id,
                'model' => $cost->model,
                'input_tokens' => $cost->input_tokens,
                'output_tokens' => $cost->output_tokens,
                'cache_write_tokens' => $cost->cache_write_tokens,
                'cache_read_tokens' => $cost->cache_read_tokens,
                'cost_micros' => $cost->cost_micros,
                'cost_usd_micros' => $cost->cost_usd_micros,
                'eur_per_usd' => $cost->eur_per_usd,
            ]);
        } catch (Throwable $e) {
            Log::error('Kon assistentverbruik niet vastleggen', [
                'model' => $cost->model,
                'exception' => $e,
            ]);
        }

        if (!$cost->isPriced()) {
            Log::warning('Geen prijs bekend voor model, verbruik geteld als nul', ['model' => $cost->model]);
        }

        return $cost->cost_micros;
    }

    /** @return array<int, string> */
    private function spokenText(Message $response): array
    {
        $said = [];

        foreach ($response->content as $block) {
            if ($block instanceof TextBlock && filled(trim($block->text))) {
                $said[] = $block->text;
            }
        }

        return $said;
    }

    /** @return array<int, Tool> */
    private function definitions(User $user): array
    {
        return array_map(
            fn (array $definition) => Tool::with(
                inputSchema: $definition['input_schema'],
                name: $definition['name'],
                description: $definition['description'],
                strict: $definition['strict'],
            ),
            $this->registry->definitionsFor($user),
        );
    }
}

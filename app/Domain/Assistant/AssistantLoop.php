<?php

namespace App\Domain\Assistant;

use App\Domain\Assistant\Contracts\AssistantSaidTurn;
use App\Domain\Assistant\Contracts\AssistantTurn;
use App\Domain\Assistant\Contracts\Attachment;
use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\ModelToolCall;
use App\Domain\Assistant\Contracts\ModelToolResult;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\ToolResultsTurn;
use App\Domain\Assistant\Contracts\UserTurn;
use App\Domain\Tools\ToolCall;
use App\Domain\Tools\ToolExecutor;
use App\Domain\Tools\ToolRegistry;
use App\Domain\Tools\ToolResult;
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
 * Nothing here knows which supplier is answering. Turns and tool definitions go
 * out through TalksToModel and a ModelReply comes back, so changing supplier is
 * writing one adapter — see Providers/AnthropicModel.
 *
 * The loop owns the two things that are easy to get wrong. The model's own turn
 * must go back exactly as it arrived, which is why it travels as an opaque blob
 * rather than something rebuilt from the text. And every tool call must be
 * answered, in one message, even the ones that failed — a missing result leaves
 * the conversation permanently unanswerable.
 */
class AssistantLoop
{
    public function __construct(
        private readonly ModelPicker $models,
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $executor,
    ) {}

    /**
     * @param  Closure(string):void|null  $onText  Called with each sentence the model writes.
     * @param  Closure(string, array, bool, ToolResult):void|null  $onTool  Name, arguments, whether it failed, and the result itself.
     * @param  array<int, array{question: string, answer: string}>  $history  Earlier exchanges, oldest first.
     */
    public function ask(
        User $user,
        string $question,
        string $system,
        int $max_rounds = 6,
        ?Closure $onText = null,
        ?Closure $onTool = null,
        string $context = '',
        ?int $difficulty = null,
        array $history = [],
        array $attachments = [],
        bool $needs_vision = false,
    ): AssistantAnswer {
        $tools = $this->registry->definitionsFor($user);

        /**
         * The cheapest model equal to the hardest tool this person can reach
         * for, unless the caller insists on one.
         */
        $wanted = $difficulty ?? $this->registry->requiredDifficultyFor($user);
        $model = $this->models->forDifficulty($wanted, needs_vision: $needs_vision || $attachments !== []);

        /**
         * The tools ask what the model can do, and until now they asked the
         * container — which hands back the configured default, not the one this
         * question is actually being answered by. view_images checked a model
         * with eyes while DeepSeek did the answering, and the photos went into
         * the dark.
         */
        app()->instance(TalksToModel::class, $model);
        /**
         * Earlier exchanges go back as words rather than as the replies they
         * came from: what was actually said is all a follow-up needs, and the
         * tool calls behind it would cost far more to carry than they are worth.
         *
         * The context rides with the newest question only, because the page
         * someone is looking at is the page they are on now, not the one they
         * were on two questions ago.
         */
        $turns = [];

        foreach ($history as $exchange) {
            $turns[] = new UserTurn([$exchange['question']]);
            $turns[] = new AssistantSaidTurn($exchange['answer']);
        }

        $turns[] = new UserTurn($context === '' ? [$question] : [$context, $question], $attachments);
        $rounds = 0;
        $spoken = [];
        $spent = 0;
        $searched = [];

        while (true) {
            $reply = $model->send($turns, $tools, $system);

            $spent += $this->recordCost($user, $reply);
            $searched = array_merge($searched, $reply->searched);

            if ($reply->stop_reason === StopReason::refused) {
                throw new RuntimeException('Het model heeft de vraag geweigerd.');
            }

            /**
             * A turn that ran out of room is not an answer, and it is the one
             * failure that looks like success: half a list of werkbonnen reads
             * exactly like a whole one. Better to say nothing than to let someone
             * act on it.
             */
            if ($reply->stop_reason === StopReason::out_of_room) {
                throw new RuntimeException(
                    'Het antwoord paste niet binnen max_tokens en is afgekapt. '
                    . 'Verhoog ASSISTANT_MAX_TOKENS (nu ' . config('assistant.max_tokens') . ').'
                );
            }

            foreach ($reply->texts as $text) {
                $spoken[] = $text;
                $onText && $onText($text);
            }

            if ($reply->tool_calls === []) {
                /**
                 * Blank line between blocks: they are separate thoughts, and a
                 * single newline is markdown for "the same paragraph continues",
                 * which runs "Ik zoek het even op" into the answer itself.
                 */
                return new AssistantAnswer(implode("\n\n", $spoken), $rounds, $reply, $spent, $searched);
            }

            if (++$rounds > $max_rounds) {
                /**
                 * What it already said is kept. Six rounds in, the model has often
                 * named the customer and found the werkbon and is off looking up a
                 * third thing; throwing all of that away to report "zonder
                 * antwoord" loses work that was done and states something untrue.
                 *
                 * Said plainly, though, so half an answer cannot pass for a whole
                 * one — the sentence saying it stopped is part of the answer, not a
                 * flag somebody downstream has to remember to render.
                 */
                if ($spoken !== []) {
                    $spoken[] = 'Ik ben hier gestopt: dit kostte te veel stappen. '
                        . 'Wat hierboven staat klopt, maar het is nog geen antwoord op de hele vraag. '
                        . 'Stel hem eventueel smaller.';

                    return new AssistantAnswer(implode("\n\n", $spoken), $rounds, $reply, $spent, $searched);
                }

                throw new RuntimeException('Gestopt na ' . $max_rounds . ' tool-rondes zonder antwoord.');
            }

            $turns[] = new AssistantTurn($reply->raw);

            $attachments = [];
            $turns[] = new ToolResultsTurn($this->run($reply->tool_calls, $user, $onTool, $attachments));

            /**
             * Files a tool handed back go in as their own turn, after the results
             * that announced them. A tool result is text by the time a supplier
             * sees it, so a datasheet cannot travel inside one.
             */
            if ($attachments !== []) {
                /**
                 * Not a chance to swap in a model with eyes, however much this
                 * round needs them: an assistant turn carries the supplier's own
                 * raw content, and handing DeepSeek's to Anthropic gets
                 * "messages.1.content: Input should be a valid array". Whether
                 * this conversation can see is settled before the first round.
                 */
                $turns[] = new UserTurn(['De opgevraagde bestanden:'], $attachments);
            }
        }
    }

    /**
     * @param  array<int, ModelToolCall>  $calls
     * @return array<int, ModelToolResult>
     */
    /**
     * @param  array<int, Attachment>  $attachments  Filled with anything the tools want shown.
     */
    private function run(array $calls, User $user, ?Closure $onTool, array &$attachments = []): array
    {
        $results = [];

        foreach ($calls as $call) {
            $result = $this->executor->run(new ToolCall(
                name: $call->name,
                arguments: $call->arguments,
                user: $user,
                external_id: $call->id,
            ));

            $onTool && $onTool($call->name, $call->arguments, $result->is_error, $result);

            foreach ($result->attachments as $attachment) {
                $attachments[] = $attachment;
            }

            $results[] = new ModelToolResult(
                call_id: $call->id,
                content: $result->toModelContent(),
                is_error: $result->is_error,
            );
        }

        return $results;
    }

    /**
     * Writes down what the call cost and returns it, so the caller can show the
     * price of one question without adding the rows back up.
     *
     * A failed write must not lose the answer someone is waiting for, so it is
     * reported and swallowed — the same position the activity log takes. The
     * meter can undercount when the database is unhappy, which is the right way
     * round: an unbilled call beats a lost answer.
     */
    private function recordCost(User $user, ModelReply $reply): int
    {
        $cost = UsageCost::forCall($reply->billableModel(), $reply->usage);

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
            Log::error('Kon assistentverbruik niet vastleggen', ['model' => $cost->model, 'exception' => $e]);
        }

        if (!$cost->isPriced()) {
            Log::warning('Geen prijs bekend voor model, verbruik geteld als nul', ['model' => $cost->model]);
        }

        return $cost->cost_micros;
    }
}

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
use App\Models\User;
use Closure;
use RuntimeException;

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

        while (true) {
            $response = $this->model->send($messages, $tools, $system);

            if ($response->stopReason === 'refusal') {
                throw new RuntimeException('Het model heeft de vraag geweigerd.');
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
                return new AssistantAnswer(implode("\n", $spoken), $rounds, $response);
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
            ),
            $this->registry->definitionsFor($user),
        );
    }
}

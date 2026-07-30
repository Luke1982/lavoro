<?php

namespace App\Domain\Assistant\Providers;

use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlock;
use Anthropic\Messages\TextBlockParam;
use Anthropic\Messages\Tool;
use Anthropic\Messages\ToolResultBlockParam;
use Anthropic\Messages\ToolUseBlock;
use App\Domain\Assistant\Contracts\AssistantSaidTurn;
use App\Domain\Assistant\Contracts\AssistantTurn;
use App\Domain\Assistant\Contracts\ModelFailure;
use App\Domain\Assistant\Contracts\ModelReply;
use App\Domain\Assistant\Contracts\ModelToolCall;
use App\Domain\Assistant\Contracts\ModelUnavailable;
use App\Domain\Assistant\Contracts\StopReason;
use App\Domain\Assistant\Contracts\TalksToModel;
use App\Domain\Assistant\Contracts\TokenUsage;
use App\Domain\Assistant\Contracts\ToolResultsTurn;
use App\Domain\Assistant\Contracts\UserTurn;

/**
 * Everything Anthropic-shaped lives here and nowhere else.
 *
 * That is the whole point of the class: the loop, the tools, the meter and the
 * allowance all work in neutral terms, and this translates in both directions.
 * A second supplier is another file like this one, not a change anywhere else.
 */
class AnthropicModel implements TalksToModel
{
    /**
     * The config entry this instance speaks for, so the same driver can serve more
     * than one — the assistant on a capable model, the question sorter on a cheap
     * one — without either knowing about the other.
     */
    public function __construct(
        private readonly Client $client,
        private readonly string $provider = 'anthropic',
    ) {}

    private function setting(string $key, mixed $default = null): mixed
    {
        return config('assistant.providers.' . $this->provider . '.' . $key, $default);
    }

    public function send(array $turns, array $tools, string $system): ModelReply
    {
        try {
            $response = $this->client->messages->create(
                maxTokens: (int) ($this->setting('max_tokens') ?? config('assistant.max_tokens')),
                messages: array_map(fn ($turn) => $this->toMessage($turn), $turns),
                model: $this->setting('model'),
                system: [$this->cacheableSystem($system)],
                tools: array_map(fn (array $tool) => $this->toTool($tool), $tools),
            );
        } catch (APIConnectionException $e) {
            throw new ModelUnavailable(ModelFailure::unreachable, $e->getMessage());
        } catch (APIStatusException $e) {
            throw new ModelUnavailable($this->classify($e), $e->getMessage());
        }

        return $this->toReply($response);
    }

    /**
     * Anthropic reports an empty account as a 400 with a phrase in the body
     * rather than a status of its own, so the phrase is what has to be read.
     * Knowing that is precisely the kind of thing that belongs in an adapter.
     */
    private function classify(APIStatusException $e): ModelFailure
    {
        if (str_contains($e->getMessage(), 'credit balance')) {
            return ModelFailure::no_credit;
        }

        return $e->status === 401 ? ModelFailure::bad_credentials : ModelFailure::other;
    }

    /**
     * The tool definitions and the system prompt are identical on every turn and
     * together they are most of what gets sent, so they are marked cacheable.
     * Reading them back costs a tenth of writing them.
     *
     * The marker goes on the system block because the request is assembled
     * tools, then system, then messages — so caching the last system block
     * covers the tools with it. That ordering is Anthropic's, which is exactly
     * why this knowledge belongs in here and not in the loop.
     */
    private function cacheableSystem(string $system): TextBlockParam
    {
        return TextBlockParam::with(text: $system, cacheControl: ['type' => 'ephemeral']);
    }

    /**
     * @param  array{name: string, description: string, input_schema: array<string, mixed>, strict: bool}  $tool
     */
    private function toTool(array $tool): Tool
    {
        return Tool::with(
            inputSchema: $tool['input_schema'],
            name: $tool['name'],
            description: $tool['description'],
            strict: $tool['strict'],
        );
    }

    public function readsDocuments(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    private function toMessage(mixed $turn): array
    {
        if ($turn instanceof UserTurn) {
            /**
             * A plain string when there is nothing but one line of text, because
             * that is the cheapest shape and by far the common one. Anything else
             * becomes blocks — including files, which this supplier reads itself
             * rather than needing them turned into text first.
             */
            if ($turn->attachments === [] && count($turn->texts) === 1) {
                return ['role' => 'user', 'content' => $turn->texts[0]];
            }

            $content = array_map(
                fn (string $text) => ['type' => 'text', 'text' => $text],
                $turn->texts,
            );

            foreach ($turn->attachments as $attachment) {
                $content[] = [
                    'type' => 'document',
                    'source' => [
                        'type' => 'base64',
                        'media_type' => $attachment->media_type,
                        'data' => $attachment->base64,
                    ],
                    'title' => $attachment->name,
                ];
            }

            return ['role' => 'user', 'content' => $content];
        }

        if ($turn instanceof AssistantTurn) {
            return ['role' => 'assistant', 'content' => $turn->raw];
        }

        if ($turn instanceof AssistantSaidTurn) {
            return ['role' => 'assistant', 'content' => $turn->text];
        }

        if ($turn instanceof ToolResultsTurn) {
            return [
                'role' => 'user',
                'content' => array_map(
                    fn ($result) => ToolResultBlockParam::with(
                        toolUseID: $result->call_id,
                        content: $result->content,
                        isError: $result->is_error,
                    ),
                    $turn->results,
                ),
            ];
        }

        throw new \InvalidArgumentException('Onbekend soort beurt: ' . get_debug_type($turn));
    }

    private function toReply(Message $response): ModelReply
    {
        $texts = [];
        $calls = [];

        foreach ($response->content as $block) {
            if ($block instanceof TextBlock && filled(trim($block->text))) {
                $texts[] = $block->text;
            }

            if ($block instanceof ToolUseBlock) {
                $calls[] = new ModelToolCall(id: $block->id, name: $block->name, arguments: $block->input);
            }
        }

        return new ModelReply(
            texts: $texts,
            tool_calls: $calls,
            usage: new TokenUsage(
                input: $response->usage->inputTokens,
                output: $response->usage->outputTokens,
                cache_write: $response->usage->cacheCreationInputTokens ?? 0,
                cache_read: $response->usage->cacheReadInputTokens ?? 0,
            ),
            stop_reason: $this->toStopReason($response),
            model: (string) $response->model,

            /** Kept whole: thinking blocks are signed and must go back untouched. */
            requested_model: (string) $this->setting('model'),
            raw: $response->content,
        );
    }

    private function toStopReason(Message $response): StopReason
    {
        return match ($response->stopReason) {
            'refusal' => StopReason::refused,
            'max_tokens' => StopReason::out_of_room,
            'tool_use' => StopReason::wants_tools,
            default => $response->content === [] ? StopReason::finished : (
                $this->hasToolUse($response) ? StopReason::wants_tools : StopReason::finished
            ),
        };
    }

    private function hasToolUse(Message $response): bool
    {
        foreach ($response->content as $block) {
            if ($block instanceof ToolUseBlock) {
                return true;
            }
        }

        return false;
    }
}

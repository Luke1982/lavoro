<?php

namespace App\Domain\Assistant;

use Anthropic\Client;
use Anthropic\Messages\Message;
use Anthropic\Messages\TextBlockParam;

class AnthropicModel implements TalksToModel
{
    public function __construct(private readonly Client $client) {}

    /**
     * The tool definitions and the system prompt are the same on every turn and
     * together they are most of what gets sent, so they are marked cacheable.
     * Reading them back costs a tenth of writing them, which is the difference
     * between an allowance that lasts a month and one that does not.
     *
     * The marker sits on the system block because the request is assembled
     * tools-then-system-then-messages: caching the last system block therefore
     * covers the tools as well, and nothing after it.
     *
     * This only pays off while the prompt in front of it stays byte-identical.
     * Anything that varies per person or per day belongs in the messages, not
     * here — see the system prompt itself, which is deliberately impersonal.
     */
    public function send(array $messages, array $tools, string $system): Message
    {
        return $this->client->messages->create(
            maxTokens: (int) config('assistant.max_tokens'),
            messages: $messages,
            model: config('assistant.model'),
            system: [TextBlockParam::with(text: $system, cacheControl: ['type' => 'ephemeral'])],
            tools: $tools,
        );
    }
}

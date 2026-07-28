<?php

namespace App\Domain\Assistant;

use Anthropic\Client;
use Anthropic\Messages\Message;

class AnthropicModel implements TalksToModel
{
    public function __construct(private readonly Client $client) {}

    public function send(array $messages, array $tools, string $system): Message
    {
        return $this->client->messages->create(
            maxTokens: (int) config('assistant.max_tokens'),
            messages: $messages,
            model: config('assistant.model'),
            system: $system,
            tools: $tools,
        );
    }
}

<?php

namespace App\Domain\Assistant;

use Anthropic\Messages\Message;

final class AssistantAnswer
{
    public function __construct(
        public readonly string $text,
        public readonly int $tool_rounds,
        public readonly Message $final,
    ) {}

    public function inputTokens(): int
    {
        return $this->final->usage->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->final->usage->outputTokens;
    }
}

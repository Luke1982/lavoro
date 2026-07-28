<?php

namespace App\Domain\Assistant;

use Anthropic\Messages\Message;

final class AssistantAnswer
{
    /**
     * @param  int  $cost_micros  Millionths of a euro, summed over every call the
     *                            question took — not just the one that answered it.
     */
    public function __construct(
        public readonly string $text,
        public readonly int $tool_rounds,
        public readonly Message $final,
        public readonly int $cost_micros = 0,
    ) {}

    public function costEuros(): float
    {
        return $this->cost_micros / 1_000_000;
    }

    public function inputTokens(): int
    {
        return $this->final->usage->inputTokens;
    }

    public function outputTokens(): int
    {
        return $this->final->usage->outputTokens;
    }
}

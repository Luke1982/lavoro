<?php

namespace App\Domain\Assistant;

use Anthropic\Messages\Message;

/**
 * One turn of conversation with the model.
 *
 * The loop that drives the tools has nothing to say about HTTP, so it depends on
 * this instead of on a client. That keeps the loop testable without a network or
 * a bill, and it is the seam the streaming version will replace when the
 * assistant moves from a command into the interface.
 *
 * @phpstan-type ToolDefinitions array<int, mixed>
 */
interface TalksToModel
{
    /**
     * @param  array<int, mixed>  $messages
     * @param  array<int, mixed>  $tools
     */
    public function send(array $messages, array $tools, string $system): Message;
}

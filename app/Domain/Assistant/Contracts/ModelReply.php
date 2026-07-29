<?php

namespace App\Domain\Assistant\Contracts;

/**
 * One answer from the model, in the shape the loop reasons about.
 *
 * `raw` is the supplier's own version of the same turn, kept so it can be
 * replayed verbatim on the next request. Everything else here is normalised, so
 * swapping supplier means writing one adapter rather than touching the loop.
 */
final class ModelReply
{
    /**
     * @param  array<int, string>  $texts
     * @param  array<int, ModelToolCall>  $tool_calls
     */
    public function __construct(
        public readonly array $texts,
        public readonly array $tool_calls,
        public readonly TokenUsage $usage,
        public readonly StopReason $stop_reason,
        public readonly string $model,
        public readonly mixed $raw,
    ) {}
}

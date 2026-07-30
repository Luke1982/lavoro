<?php

namespace App\Domain\Assistant\Contracts;

use App\Domain\Assistant\Pricing;

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
        /**
         * The model that was asked for, when it differs from the one that
         * answered.
         *
         * Suppliers publish aliases: deepseek-chat answered as deepseek-v4-flash.
         * Prices are held against what we send, so pricing purely on what came
         * back records every call at nought the day an alias moves — which is
         * exactly how a bill goes missing without anything looking broken.
         */
        public readonly ?string $requested_model = null,
    ) {}

    /**
     * The name to price this call under: what answered if we know it, otherwise
     * what was asked for.
     */
    public function billableModel(): string
    {
        if (Pricing::forModel($this->model) !== null) {
            return $this->model;
        }

        return $this->requested_model ?? $this->model;
    }
}

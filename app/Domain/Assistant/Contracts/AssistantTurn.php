<?php

namespace App\Domain\Assistant\Contracts;

/**
 * The model's own turn, carried back exactly as it arrived.
 *
 * Deliberately opaque. Anthropic requires its reasoning blocks to be replayed
 * untouched and signed, and other suppliers have their own such baggage — so the
 * loop stores whatever came out and hands it back without looking inside. What
 * the loop actually needs from that turn is already on ModelReply.
 */
final class AssistantTurn implements Turn
{
    public function __construct(public readonly mixed $raw) {}
}

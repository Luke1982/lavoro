<?php

namespace App\Domain\Assistant\Contracts;

/**
 * Something the assistant said earlier, as plain text.
 *
 * AssistantTurn carries a supplier's own reply back untouched, which is what a
 * running loop needs but cannot be built from nothing. Replaying an earlier
 * exchange only needs the words, so this says just that and every supplier can
 * express it.
 */
final class AssistantSaidTurn implements Turn
{
    public function __construct(public readonly string $text) {}
}

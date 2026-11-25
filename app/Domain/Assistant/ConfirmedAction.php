<?php

namespace App\Domain\Assistant;

use App\Domain\Tools\ConfirmationToken;
use App\Domain\Tools\ToolResult;

/**
 * What one click on a confirmation came to: what was approved, what carrying it
 * out returned, and the line the box shows under the button. The box and the
 * eval both read it from here, so they cannot say different things about the
 * same click.
 */
final class ConfirmedAction
{
    public const EXPIRED = 'Deze bevestiging is verlopen of hoort niet bij jou. Stel de vraag opnieuw.';

    private function __construct(
        public readonly ?ConfirmationToken $approval,
        public readonly ?ToolResult $result,
        public readonly string $message,
    ) {}

    /** The token had expired or belongs to somebody else: nothing was carried out. */
    public static function expired(): self
    {
        return new self(null, null, self::EXPIRED);
    }

    public static function carriedOut(ConfirmationToken $approval, ToolResult $result): self
    {
        return new self($approval, $result, $result->summary ?? ($result->is_error ? 'Het is niet gelukt.' : 'Gelukt.'));
    }

    public function succeeded(): bool
    {
        return $this->result !== null && !$this->result->is_error;
    }
}

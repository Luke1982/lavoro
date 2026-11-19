<?php

namespace App\Domain\Integrations\Data;

/**
 * What came back when a document was sent: its id on the other side, the number
 * it got there when it has one, and what was left out on the way.
 */
class PushedDocument
{
    /** @param  array<int, string>  $warnings */
    public function __construct(
        public string $id,
        public ?string $number = null,
        public array $warnings = [],
    ) {}
}

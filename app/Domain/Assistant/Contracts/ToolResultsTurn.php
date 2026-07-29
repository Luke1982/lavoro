<?php

namespace App\Domain\Assistant\Contracts;

final class ToolResultsTurn implements Turn
{
    /** @param array<int, ModelToolResult> $results */
    public function __construct(public readonly array $results) {}
}

<?php

namespace App\Domain\Assistant\Contracts;

/**
 * The model asking for a tool to be run. The id is whatever the supplier calls
 * it and is only ever handed back to them, so its shape is theirs to decide.
 */
final class ModelToolCall
{
    /** @param array<string, mixed> $arguments */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly array $arguments,
    ) {}
}

<?php

namespace App\Domain\Assistant\Contracts;

final class ModelToolResult
{
    public function __construct(
        public readonly string $call_id,
        public readonly string $content,
        public readonly bool $is_error = false,
    ) {}
}

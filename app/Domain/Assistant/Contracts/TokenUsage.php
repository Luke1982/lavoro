<?php

namespace App\Domain\Assistant\Contracts;

/**
 * What one call consumed, in the four counts every provider bills on in some
 * form. A supplier without a cache simply reports nothing for those two.
 */
final class TokenUsage
{
    public function __construct(
        public readonly int $input = 0,
        public readonly int $output = 0,
        public readonly int $cache_write = 0,
        public readonly int $cache_read = 0,
    ) {}
}

<?php

namespace App\Domain\Assistant\Contracts;

final class UserTurn implements Turn
{
    /** @param array<int, string> $texts */
    public function __construct(public readonly array $texts) {}
}

<?php

namespace App\Domain\Assistant\Contracts;

use RuntimeException;

final class ModelUnavailable extends RuntimeException
{
    public function __construct(public readonly ModelFailure $reason, string $message)
    {
        parent::__construct($message);
    }
}

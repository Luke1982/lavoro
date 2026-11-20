<?php

namespace App\Domain\Access;

use App\Exceptions\Refusal;

/** A link that takes one answer was answered already, or withdrawn in the meantime. */
class LinkAlreadyAnswered extends Refusal
{
    public function __construct()
    {
        parent::__construct('Op deze link is al gereageerd.');
    }
}

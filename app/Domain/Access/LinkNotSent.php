<?php

namespace App\Domain\Access;

use App\Exceptions\Refusal;

/** The mail with a link did not go out; the link has been withdrawn and nothing else happened. */
class LinkNotSent extends Refusal
{
    public function __construct()
    {
        parent::__construct('De e-mail kon niet verzonden worden. Er is niets verstuurd.');
    }
}

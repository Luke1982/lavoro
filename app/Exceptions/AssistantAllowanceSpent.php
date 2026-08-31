<?php

namespace App\Exceptions;

use RuntimeException;

class AssistantAllowanceSpent extends RuntimeException
{
    public function __construct()
    {
        parent::__construct(
            'Het AI-tegoed van deze maand is op. Volgende maand staat er weer nieuw tegoed klaar,'
            . ' of vraag je beheerder om extra tegoed bij te kopen.'
        );
    }
}

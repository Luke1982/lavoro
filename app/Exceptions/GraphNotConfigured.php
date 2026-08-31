<?php

namespace App\Exceptions;

use RuntimeException;

class GraphNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Er is nog geen mailbox ingesteld. Vul de Microsoft 365-gegevens in bij Beheer → Koppelingen.');
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

class MailNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Er is nog geen mailserver ingesteld. Vul de gegevens in bij Technisch beheer.');
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

class SnelStartNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('SnelStart is nog niet gekoppeld. Vul de sleutels in bij Technisch beheer.');
    }
}

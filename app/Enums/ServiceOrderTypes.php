<?php

namespace App\Enums;

enum ServiceOrderTypes: string
{
    case installation = 'installation';
    case service      = 'service';
    case mixed        = 'mixed';
}

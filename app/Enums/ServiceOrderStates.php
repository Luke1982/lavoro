<?php

namespace App\Enums;

enum ServiceOrderStates: string
{
    case gemaakt     = 'Gemaakt';
    case gepland     = 'Gepland';
    case afgerond    = 'Afgerond';
    case geannuleerd = 'Geannuleerd';
}

<?php

namespace App\Enums;

enum ServiceJobOutcomes: string
{
    case goedkeur           = 'Goedkeur';
    case afkeur             = 'Afkeur';
    case reparatie          = 'Goedkeur na reparatie';
    case tijdelijk_goedkeur = 'Tijdelijke goedkeur';
}

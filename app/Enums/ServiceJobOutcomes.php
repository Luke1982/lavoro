<?php

namespace App\Enums;

enum ServiceJobOutcomes: string
{
    case goedkeur           = 'Goedkeur';
    case afkeur             = 'Afkeur';
    case reparatie          = 'Goedkeur na reparatie';
    case tijdelijk_goedkeur = 'Tijdelijke goedkeur';
    case nog_geen_uitkomst  = 'Nog geen uitkomst';
}

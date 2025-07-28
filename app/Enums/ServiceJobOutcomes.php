<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum ServiceJobOutcomes: string
{
    use EnumComboBoxArrayTrait;

    case goedkeur           = 'Goedkeur';
    case afkeur             = 'Afkeur';
    case reparatie          = 'Goedkeur na reparatie';
    case tijdelijk_goedkeur = 'Tijdelijke goedkeur';
    case nog_geen_uitkomst  = 'Nog geen uitkomst';
}

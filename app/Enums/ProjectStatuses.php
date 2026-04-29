<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum ProjectStatuses: string
{
    use EnumComboBoxArrayTrait;

    case niet_gestart = 'Niet gestart';
    case gestart      = 'Gestart';
    case afgerond     = 'Afgerond';
    case geannuleerd  = 'Geannuleerd';
}

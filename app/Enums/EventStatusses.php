<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;
use App\Enums\Traits\EnumValueArrayTrait;

enum EventStatusses: string
{
    use EnumValueArrayTrait;
    use EnumComboBoxArrayTrait;

    case planned   = 'Gepland';
    case ongoing   = 'Gaande';
    case completed = 'Afgerond';
    case cancelled = 'Geannuleerd';
}

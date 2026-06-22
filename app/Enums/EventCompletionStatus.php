<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;
use App\Enums\Traits\EnumValueArrayTrait;

enum EventCompletionStatus: string
{
    use EnumComboBoxArrayTrait;
    use EnumValueArrayTrait;

    case planned = 'Gepland';
    case ongoing = 'Gaande';
    case completed = 'Afgerond';
    case cancelled = 'Geannuleerd';
}

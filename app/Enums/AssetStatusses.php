<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;
use App\Enums\Traits\EnumValueArrayTrait;

enum AssetStatusses: string
{
    use EnumValueArrayTrait;

    case active   = 'Actief';
    case inactive = 'Niet actief';
}

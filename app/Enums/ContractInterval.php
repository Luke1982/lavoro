<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum ContractInterval: string
{
    use EnumComboBoxArrayTrait;

    case maandelijks = 'Maandelijks';
    case halfjaarlijks = 'Halfjaarlijks';
    case jaarlijks = 'Jaarlijks';
    case aangepast = 'Aangepast (dagen)';
}

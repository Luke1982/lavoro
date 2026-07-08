<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum StandardEmailTriggerType: string
{
    use EnumComboBoxArrayTrait;

    case background = 'Automatisch versturen';
    case confirm = 'Bevestigen voor verzenden';
    case allowedit = 'Bewerken voor verzenden';
}

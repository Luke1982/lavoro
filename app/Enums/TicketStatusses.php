<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum TicketStatusses: string
{
    use EnumComboBoxArrayTrait;

    case open = 'Open';
    case in_behandeling = 'In behandeling';
    case wacht_op_klant = 'Wacht op terugkoppeling klant';
    case gesloten = 'Gesloten';
}

<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum TicketPriorities: string
{
    use EnumComboBoxArrayTrait;

    case laag    = 'Laag';
    case normaal = 'Normaal';
    case hoog    = 'Hoog';
}

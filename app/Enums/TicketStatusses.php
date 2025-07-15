<?php

namespace App\Enums;

enum TicketStatusses: string
{
    case open           = 'Open';
    case in_behandeling = 'In behandeling';
    case gesloten       = 'Gesloten';
}

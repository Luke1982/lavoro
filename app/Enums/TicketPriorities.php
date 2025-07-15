<?php

namespace App\Enums;

enum TicketPriorities: string
{
    case laag    = 'Laag';
    case normaal = 'Normaal';
    case hoog    = 'Hoog';
}

<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboBoxArrayTrait;

enum EventTrigger: string
{
    use EnumComboBoxArrayTrait;

    case event_created = 'Afspraak aangemaakt';
    case event_updated = 'Afspraak gewijzigd';
    case event_deleted = 'Afspraak verwijderd';
}

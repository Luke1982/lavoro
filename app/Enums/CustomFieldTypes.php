<?php

namespace App\Enums;

use App\Enums\Traits\EnumComboArrayTrait;

enum CustomFieldTypes: string
{
    use EnumComboArrayTrait;

    case text     = 'Tekst';
    case number   = 'Nummer';
    case date     = 'Datum';
    case boolean  = 'Ja/Nee';
    case select   = 'Keuzelijst';
    case textarea = 'Tekstvak';
}

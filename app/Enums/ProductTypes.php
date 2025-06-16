<?php

namespace App\Enums;

enum ProductTypes: string
{
    case lift          = 'Hefbrug';
    case tirechanger   = 'Bandenwisselaar';
    case wheelbalancer = 'Balanceermachine';
    case compressor    = 'Compressor';
    case alignment     = 'Uitlijnapparatuur';
    case other         = 'Overig';
}

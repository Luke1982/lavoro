<?php

namespace App\Domain\Quotes;

/** The Dutch VAT rates a quote line can carry, the usual one first. */
final class VatRates
{
    public const DEFAULT = 21;

    public const ALL = [21, 9, 0];
}

<?php

namespace App\Enums;

enum QuoteLineKinds: string
{
    case product = 'product';
    case material = 'material';
    case free = 'free';
    case text = 'text';

    /** A text line is a heading or a paragraph: it has no amount and counts for nothing. */
    public function isPriced(): bool
    {
        return $this !== self::text;
    }
}

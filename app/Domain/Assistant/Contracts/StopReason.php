<?php

namespace App\Domain\Assistant\Contracts;

/**
 * Why the model stopped. Suppliers spell these differently; an adapter maps its
 * own vocabulary onto these four so the loop never learns anyone's dialect.
 */
enum StopReason: string
{
    case finished = 'finished';
    case wants_tools = 'wants_tools';
    case out_of_room = 'out_of_room';
    case refused = 'refused';
}

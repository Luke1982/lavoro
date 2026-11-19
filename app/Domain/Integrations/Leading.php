<?php

namespace App\Domain\Integrations;

/**
 * Whose version wins when a record changed on both sides since the last sync.
 * A change on only one side always goes across; this only settles a clash.
 */
enum Leading: string
{
    case External = 'external';
    case Lavoro = 'lavoro';
}

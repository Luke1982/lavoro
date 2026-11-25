<?php

namespace App\Domain\Assistant\Evals;

/**
 * How a run came to an end. Only a simulated user who says it is done counts as
 * a conversation that finished; everything else is a run that went wrong, and a
 * check on what it made means little beside it.
 */
enum EvalEnding: string
{
    case user_stopped = 'user_stopped';
    case invalid_move = 'invalid_move';
    case error = 'error';
    case cut_off = 'cut_off';

    public function label(): string
    {
        return match ($this) {
            self::user_stopped => 'gebruiker stopte',
            self::invalid_move => 'ongeldige zet van de gesimuleerde gebruiker',
            self::error => 'fout',
            self::cut_off => 'afgekapt',
        };
    }
}

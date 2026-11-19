<?php

namespace App\Domain\Integrations;

/**
 * How a pull finds out what changed on the other side.
 *
 * Every area a connection reads is compared in full every night: that is the
 * only way to notice a record that disappeared there, and the safety net under
 * everything quicker. A system that can answer "what changed since" (Delta) is
 * also asked that every quarter of an hour. A system that calls Lavoro itself
 * has the Webhooks capability on top; that is not a way of detecting changes
 * but a way of hearing about them sooner.
 */
enum ChangeDetection: string
{
    case Full = 'full';
    case Delta = 'delta';

    public function label(): string
    {
        return match ($this) {
            self::Full => 'elke nacht alles vergelijken',
            self::Delta => 'elk kwartier de wijzigingen, en elke nacht alles vergelijken',
        };
    }
}

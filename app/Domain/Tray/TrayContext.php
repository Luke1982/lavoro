<?php

namespace App\Domain\Tray;

use App\Support\ForgetsTenantState;

/**
 * Marks the stretch of a request during which something is being pasted off the
 * bakje.
 *
 * BaseSignal reads this when it is raised, so every fact that comes out of a
 * paste is recorded as done via the bakje without an action, model or listener
 * having to know the bakje exists. The person is still the actor; this only says
 * how they did it.
 */
class TrayContext implements ForgetsTenantState
{
    public const VIA = 'tray';

    private int $depth = 0;

    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function run(callable $work): mixed
    {
        $this->depth++;

        try {
            return $work();
        } finally {
            $this->depth--;
        }
    }

    public function forgetTenantState(): void
    {
        $this->depth = 0;
    }
}

<?php

namespace App\Domain\Assistant;

use App\Models\User;

/**
 * Marks the stretch of a request during which the assistant, rather than the
 * person, is doing the acting.
 *
 * BaseSignal reads this when it resolves its actor, so every fact raised inside
 * that stretch is recorded as machine-made without a single Action, model or
 * listener having to know the assistant exists. The signed-in user is still
 * recorded as the actor: they asked for it, so it is on their timeline and it is
 * their accountability. Only actor_type tells the two apart.
 *
 * Registered as a singleton and reset between queue jobs, so a worker that
 * handles an assistant job followed by an ordinary one does not mislabel the
 * second.
 */
class AssistantContext
{
    private ?User $on_behalf_of = null;

    private int $depth = 0;

    public function isActive(): bool
    {
        return $this->depth > 0;
    }

    public function onBehalfOf(): ?User
    {
        return $this->on_behalf_of;
    }

    /**
     * Runs $work with the assistant marked as the actor, restoring the previous
     * state afterwards even when the work throws. Nesting is counted rather than
     * flagged, so a tool that calls another tool does not clear the mark early.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $work
     * @return TReturn
     */
    public function run(User $user, callable $work): mixed
    {
        $previous = $this->on_behalf_of;

        $this->on_behalf_of = $user;
        $this->depth++;

        try {
            return $work();
        } finally {
            $this->depth--;

            if ($this->depth === 0) {
                $this->on_behalf_of = null;
            } else {
                $this->on_behalf_of = $previous;
            }
        }
    }

    public function reset(): void
    {
        $this->on_behalf_of = null;
        $this->depth = 0;
    }
}

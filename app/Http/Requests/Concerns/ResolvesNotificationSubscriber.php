<?php

namespace App\Http\Requests\Concerns;

use App\Models\User;

/**
 * Whose bell is being set. Switching a notification on or off is done for
 * whoever user_id names, and for the person asking when it names nobody.
 */
trait ResolvesNotificationSubscriber
{
    /** False until looked up, which is not the same as looked up and not found. */
    private User|false|null $resolved_subscriber = false;

    /**
     * Resolved once: authorisation, the rules and the controller all ask, and none
     * of them needs its own query to find out.
     */
    public function subscriber(): ?User
    {
        if ($this->resolved_subscriber !== false) {
            return $this->resolved_subscriber;
        }

        $user_id = $this->input('user_id');

        return $this->resolved_subscriber = $user_id === null
            ? $this->user()
            : User::find($user_id);
    }
}

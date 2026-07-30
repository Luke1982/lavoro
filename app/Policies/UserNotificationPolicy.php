<?php

namespace App\Policies;

use App\Models\User;
use App\Models\UserNotification;

/**
 * A notification belongs to one person and to nobody else, admins included. An
 * admin who could mark somebody's notification as read would erase the only
 * signal that it still needs that person's attention.
 */
class UserNotificationPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function view(User $user, UserNotification $user_notification): bool
    {
        return $user_notification->user_id === $user->id;
    }

    /**
     * Acknowledging and taking that back are the only changes there are.
     */
    public function update(User $user, UserNotification $user_notification): bool
    {
        return $user_notification->user_id === $user->id;
    }
}

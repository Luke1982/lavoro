<?php

namespace App\Policies;

use App\Models\NotificationMute;
use App\Models\User;

/**
 * Switching a notification off is setting your bell, just as switching one on
 * is, so the rule is the subscriptions' own: yours freely, somebody else's with
 * the permission for it.
 */
class NotificationMutePolicy
{
    public function __construct(private NotificationSubscriptionPolicy $subscriptions) {}

    public function create(User $user, ?User $subscriber): bool
    {
        return $this->subscriptions->create($user, $subscriber);
    }

    public function delete(User $user, NotificationMute $mute): bool
    {
        return $mute->user_id === $user->id || $this->subscriptions->manageOthers($user);
    }
}

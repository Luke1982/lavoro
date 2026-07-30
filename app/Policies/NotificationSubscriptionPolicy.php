<?php

namespace App\Policies;

use App\Models\NotificationSubscription;
use App\Models\User;

/**
 * Your own bell is yours to set. Deciding what lands in somebody else's needs
 * the permission for it.
 *
 * Whether the type may be received at all is a separate question, settled by
 * validation against the subscriber's own permissions: this decides who may turn
 * the switch, not what the switch is allowed to be.
 */
class NotificationSubscriptionPolicy
{
    /**
     * A subscriber that could not be resolved is left to validation, which
     * reports an unknown user far more usefully than a refusal would.
     */
    public function create(User $user, ?User $subscriber): bool
    {
        if ($subscriber === null || $subscriber->id === $user->id) {
            return true;
        }

        return $user->hasPermission('usernotification.manage_subscriptions');
    }

    public function delete(User $user, NotificationSubscription $notification_subscription): bool
    {
        return $notification_subscription->user_id === $user->id
            || $user->hasPermission('usernotification.manage_subscriptions');
    }
}

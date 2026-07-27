<?php

namespace App\Listeners\ServiceOrders;

use App\Domain\Signals\ServiceOrders\ServiceOrderAssigned;
use App\Models\User;
use App\Notifications\NewServiceOrderAssigned;
use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;

/**
 * Tells newly assigned users about their werkbon. Owns that concern entirely:
 * whatever route put them on the order never needs to know a notification exists.
 */
class NotifyAssignedUsers implements ShouldHandleEventsAfterCommit
{
    public function handle(ServiceOrderAssigned $signal): void
    {
        if ($signal->newly_assigned_user_ids === []) {
            return;
        }

        User::whereIn('id', $signal->newly_assigned_user_ids)
            ->get()
            ->each(fn (User $user) => $user->notify(
                new NewServiceOrderAssigned($signal->serviceOrder())
            ));
    }
}

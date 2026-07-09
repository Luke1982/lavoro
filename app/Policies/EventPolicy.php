<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\User;

class EventPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.read');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.create');
    }

    public function createOthers(User $user, User $owner_user): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        if ($owner_user->id === $user->id) {
            return $user->hasPermission('event.create');
        }

        return $user->hasPermission('event.create_others');
    }

    public function update(User $user, Event $event): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        $owner = $event->owner();
        $is_owner = $owner && $owner->id === $user->id;
        if ($is_owner && $user->hasPermission('event.update')) {
            return true;
        }

        return $user->hasPermission('event.update_others');
    }

    public function delete(User $user, Event $event): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        $owner = $event->owner();
        $is_owner = $owner && $owner->id === $user->id;
        if ($is_owner && $user->hasPermission('event.delete')) {
            return true;
        }

        return $user->hasPermission('event.delete_others');
    }

    public function executeOwn(User $user, Event $event): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasPermission('event.execute') && $event->hasExecutingUser($user->id);
    }

    public function export(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.export');
    }

    public function seeBeyondCurrentWeek(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.see_beyond_current_week');
    }

    public function provideFeedback(User $user, Event $event): bool
    {
        if ($event->serviceOrders()->exists()) {
            return false;
        }

        return $user->isAdmin() || $user->hasPermission('event.provide_feedback');
    }

    public function releaseTimes(User $user, Event $event): bool
    {
        return $user->isAdmin() || $user->hasPermission('event.release_times');
    }

    public function executeOthers(User $user, Event $event, int $target_user_id): bool
    {
        if ($user->isAdmin()) {
            return true;
        }

        return $user->hasPermission('event.execute_others') && $event->hasExecutingUser($target_user_id);
    }
}

<?php

namespace App\Policies;

use App\Models\EventType;
use App\Models\User;

class EventTypePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('eventtype.read');
    }

    public function view(User $user, EventType $event_type): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('eventtype.create');
    }

    /** Asked of the class: every one of them takes the same right. */
    public function update(User $user): bool
    {
        return $user->hasPermission('eventtype.update');
    }

    public function delete(User $user): bool
    {
        return $user->hasPermission('eventtype.delete');
    }
}

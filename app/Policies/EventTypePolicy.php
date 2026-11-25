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
}

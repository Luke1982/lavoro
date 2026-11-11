<?php

namespace App\Policies;

use App\Models\DayRoute;
use App\Models\Event;
use App\Models\User;

class DayRoutePolicy
{
    /** Like appointments: whoever sees the whole planning sees every route in it. */
    public function seeEveryone(User $user): bool
    {
        return $user->hasPermission('event.see_all');
    }

    /**
     * Putting a route in someone's day is planning for them: it takes the route
     * planner and the right to plan that person.
     */
    public function assign(User $user, User $mechanic): bool
    {
        return $user->can('routeplanner.use') && $user->can('createOthers', [Event::class, $mechanic]);
    }

    public function delete(User $user, DayRoute $route): bool
    {
        return $route->user !== null && $this->assign($user, $route->user);
    }
}

<?php

namespace App\Http\Requests\Concerns;

use App\Models\ServiceOrder;
use App\Models\User;

/**
 * An afspraak can bring a new werkbon into being with it. Making a werkbon is a right of
 * its own, apart from filling in the planner, so asking for one takes that right too.
 */
trait AuthorizesServiceOrderAlongside
{
    protected function mayCreateServiceOrderAlongside(User $user): bool
    {
        return !$this->boolean('create_service_order') || $user->can('create', ServiceOrder::class);
    }
}

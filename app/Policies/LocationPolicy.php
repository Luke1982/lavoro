<?php

namespace App\Policies;

use App\Models\Location;
use App\Models\User;

class LocationPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('location.read');
    }

    public function view(User $user, Location $location): bool
    {
        return $user->hasPermission('location.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('location.create');
    }

    public function update(User $user, Location $location): bool
    {
        return $user->hasPermission('location.update');
    }

    public function delete(User $user, Location $location): bool
    {
        return $user->hasPermission('location.delete');
    }

    /**
     * Listing a customer's locations for a picker. The picker fills a field on
     * another record -- a werkbon, a machine, an appointment -- so whoever may
     * fill that field may see what there is to choose from. On the Locaties
     * rights alone, a monteur was allowed to set a machine's location while the
     * list behind it stayed closed: an empty box, and nothing to say why. The
     * app has no per-record ACL, so this is a domain-level gate, not a
     * per-customer one.
     */
    public function pickForCustomer(User $user): bool
    {
        return $user->hasAnyPermission([
            'customer.read',
            'location.read',
            'serviceorder.create',
            'serviceorder.update',
            'asset.create',
            'asset.update',
            'event.create',
            'event.update',
        ]);
    }
}

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
     * Listing a customer's locations for a picker. Locations are customer
     * sub-data, so this follows the customer read permission (or location.read
     * for the Locaties-management flows). The app has no per-record ACL, so this
     * is a domain-level gate, not a per-customer one.
     */
    public function pickForCustomer(User $user): bool
    {
        return $user->hasPermission('customer.read')
            || $user->hasPermission('location.read');
    }
}

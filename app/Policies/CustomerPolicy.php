<?php

namespace App\Policies;

use App\Models\Customer;
use App\Models\User;

class CustomerPolicy
{
    public function list(User $user): bool
    {
        return $user->hasPermission('customer.read');
    }

    public function view(User $user, Customer $customer): bool
    {
        return $user->hasPermission('customer.read');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('customer.create');
    }

    /** IBAN and btw-nummer. Asked of the class: the answer is the same for every customer. */
    public function seeSensitive(User $user): bool
    {
        return $user->hasPermission('customer.see_sensitive');
    }
}

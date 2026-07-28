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
}

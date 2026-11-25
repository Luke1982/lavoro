<?php

namespace App\Policies;

use App\Models\Company;
use App\Models\User;

/** The companies are only read on their own page, behind the admin middleware. */
class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, Company $company): bool
    {
        return $this->viewAny($user);
    }
}

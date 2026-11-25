<?php

namespace App\Policies;

use App\Models\IntegrationConnection;
use App\Models\User;

class IntegrationConnectionPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('integration.manage');
    }

    public function view(User $user, IntegrationConnection $integration): bool
    {
        return $this->viewAny($user);
    }
}

<?php

namespace App\Policies;

use App\Models\ServiceOrderStage;
use App\Models\User;

class ServiceOrderStagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('serviceorderstage.read');
    }

    public function view(User $user, ServiceOrderStage $stage): bool
    {
        return $this->viewAny($user);
    }
}

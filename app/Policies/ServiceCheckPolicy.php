<?php

namespace App\Policies;

use App\Models\ServiceCheck;
use App\Models\User;

class ServiceCheckPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('servicecheck.read');
    }

    public function view(User $user, ServiceCheck $service_check): bool
    {
        return $this->viewAny($user);
    }

    /**
     * A checkpoint's answers are part of its definition, so adding, changing,
     * ordering or removing one is editing the checkpoint. Asked of the class.
     */
    public function update(User $user): bool
    {
        return $user->hasPermission('servicecheck.update');
    }
}

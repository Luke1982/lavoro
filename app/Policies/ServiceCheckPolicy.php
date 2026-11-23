<?php

namespace App\Policies;

use App\Models\User;

class ServiceCheckPolicy
{
    /**
     * A checkpoint's answers are part of its definition, so adding, changing,
     * ordering or removing one is editing the checkpoint. Asked of the class.
     */
    public function update(User $user): bool
    {
        return $user->hasPermission('servicecheck.update');
    }
}

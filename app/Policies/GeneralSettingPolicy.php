<?php

namespace App\Policies;

use App\Models\GeneralSetting;
use App\Models\User;

/** The general settings are only read on their own page, behind the admin middleware. */
class GeneralSettingPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isAdmin();
    }

    public function view(User $user, GeneralSetting $setting): bool
    {
        return $this->viewAny($user);
    }
}

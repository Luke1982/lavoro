<?php

namespace App\Policies;

use App\Models\CustomField;
use App\Models\User;

class CustomFieldPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('customfield.read');
    }

    public function view(User $user, CustomField $custom_field): bool
    {
        return $this->viewAny($user);
    }
}

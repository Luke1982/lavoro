<?php

namespace App\Policies;

use App\Models\User;

class ProjectTemplatePolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('projecttemplate.manage');
    }
}

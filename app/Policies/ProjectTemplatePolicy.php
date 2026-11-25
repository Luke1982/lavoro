<?php

namespace App\Policies;

use App\Models\ProjectTemplate;
use App\Models\User;

class ProjectTemplatePolicy
{
    /** Templates are only shown on the page that manages them. */
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, ProjectTemplate $template): bool
    {
        return $this->viewAny($user);
    }

    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('projecttemplate.manage');
    }
}

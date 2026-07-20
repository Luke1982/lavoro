<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function list(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('project.read');
    }

    public function view(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->hasPermission('project.read');
    }

    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('project.create');
    }

    public function update(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->hasPermission('project.update');
    }

    public function delete(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->hasPermission('project.delete');
    }

    public function manageFinancials(User $user, Project $project): bool
    {
        return $user->isAdmin() || $user->hasPermission('project.manage_financials');
    }
}

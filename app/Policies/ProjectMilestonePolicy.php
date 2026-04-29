<?php

namespace App\Policies;

use App\Models\User;
use App\Models\ProjectMilestone;

class ProjectMilestonePolicy
{
    public function create(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('projectmilestone.create');
    }

    public function update(User $user, ProjectMilestone $milestone): bool
    {
        return $user->isAdmin() || $user->hasPermission('projectmilestone.update');
    }

    public function delete(User $user, ProjectMilestone $milestone): bool
    {
        return $user->isAdmin() || $user->hasPermission('projectmilestone.delete');
    }
}

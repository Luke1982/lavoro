<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\ProjectMilestone;
use App\Models\User;

/**
 * A milestone has no page of its own: it is read on its project, by whoever may
 * read that project.
 */
class ProjectMilestonePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Project::class);
    }

    public function view(User $user, ProjectMilestone $milestone): bool
    {
        return ProjectMilestone::visibleTo($user)->whereKey($milestone->id)->exists();
    }

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

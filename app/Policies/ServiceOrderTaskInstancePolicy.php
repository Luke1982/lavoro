<?php

namespace App\Policies;

use App\Models\ServiceOrderTaskInstance;
use App\Models\User;
use App\Services\TaskInstanceVisibility;
use Illuminate\Auth\Access\Response;

/**
 * Every change to a task on a werkbon asks the same three things: the permission
 * for that change, whether this person may see the werkbon and this task, and
 * whether the werkbon is still open.
 */
class ServiceOrderTaskInstancePolicy
{
    public function __construct(private readonly TaskInstanceVisibility $visibility) {}

    public function toggle(User $user, ServiceOrderTaskInstance $task): Response
    {
        return $this->change($user, $task, $this->worksOnTasks($user));
    }

    public function registerAssets(User $user, ServiceOrderTaskInstance $task): Response
    {
        return $this->change($user, $task, $this->worksOnTasks($user));
    }

    public function update(User $user, ServiceOrderTaskInstance $task): Response
    {
        return $this->change($user, $task, $user->hasPermission('serviceordertaskinstance.update'));
    }

    public function delete(User $user, ServiceOrderTaskInstance $task): Response
    {
        return $this->change($user, $task, $user->hasPermission('serviceordertaskinstance.delete'));
    }

    public function sign(User $user, ServiceOrderTaskInstance $task): Response
    {
        return $this->change($user, $task, $user->hasPermission('serviceordertaskinstance.open_close'));
    }

    public function unsign(User $user, ServiceOrderTaskInstance $task): Response
    {
        return $this->change($user, $task, $user->hasPermission('serviceordertaskinstance.open_close'));
    }

    public function cancel(User $user, ServiceOrderTaskInstance $task): Response
    {
        return $this->change($user, $task, $user->hasPermission('serviceordertaskinstance.cancel'));
    }

    /** Whether this person may see the werkbon the task is on, and the task itself. */
    public function reach(User $user, ServiceOrderTaskInstance $task): bool
    {
        return $user->can('view', $task->serviceOrder) && $this->visibility->canSee($task, $user);
    }

    private function worksOnTasks(User $user): bool
    {
        return $user->hasPermission('serviceordertaskinstance.open_close')
            || $user->hasPermission('serviceordertaskinstance.update');
    }

    /**
     * A closed werkbon is the one refusal the person can do something about, so it
     * says what: reopen it first. The others are a plain no.
     */
    private function change(User $user, ServiceOrderTaskInstance $task, bool $permitted): Response
    {
        if (!$permitted || !$this->reach($user, $task)) {
            return Response::deny();
        }

        return $task->serviceOrder->is_closed
            ? Response::deny('Deze werkbon is gesloten. Heropen hem eerst als je de taken nog wilt wijzigen.')
            : Response::allow();
    }
}

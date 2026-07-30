<?php

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;

class ServiceOrderPolicy
{
    /**
     * Whether the user may see werkbonnen at all, including the narrower case of
     * only their own. Which ones those are is decided by ServiceOrder::scopeVisibleTo,
     * which follows the same two permissions.
     */
    public function list(User $user): bool
    {
        return $user->hasPermission('serviceorder.read')
            || $user->hasPermission('serviceorder.read_own');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('serviceorder.create');
    }

    /**
     * Whether this person may put a task on a werkbon.
     *
     * Its own permission, separate from opening the werkbon: what is actually to
     * be done — which product, how many — is the part somebody works from.
     */
    /**
     * Asked before the werkbon is in hand, so it takes no werkbon. Which werkbon
     * this person may reach is a separate question, answered by the scope.
     */
    public function addTask(User $user): bool
    {
        return $user->hasPermission('serviceordertaskinstance.create');
    }

    public function update(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('serviceorder.update');
    }

    public function complete(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('serviceorder.close')
            || $user->hasPermission('serviceorder.mark_partially_complete');
    }

    public function updateStage(User $user, ServiceOrder $serviceOrder, ServiceOrderStage $stage): bool
    {
        if ($user->hasPermission('serviceorder.update')) {
            return true;
        }

        if ($user->hasPermission('serviceorder.close') && $stage->is_closed_state) {
            return true;
        }

        return $user->hasPermission('serviceorder.mark_partially_complete') && $stage->is_incomplete_state;
    }

    public function seeFinancials(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('serviceorder.see_financials');
    }

    public function viewMaterials(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('materiable.read.serviceorder');
    }

    public function attachMaterial(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('materiable.create.serviceorder');
    }

    public function updateMateriable(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('materiable.update.serviceorder');
    }

    public function detachMaterial(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('materiable.delete.serviceorder');
    }

    public function delete(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('serviceorder.delete')
            && !$serviceOrder->sent_to_administration;
    }
}

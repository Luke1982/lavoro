<?php

namespace App\Policies;

use App\Models\ServiceOrder;
use App\Models\ServiceOrderStage;
use App\Models\User;
use Illuminate\Auth\Access\Response;

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

    /**
     * This one werkbon, by the same rule as the list: everything with read, only
     * their own with read_own. A werkbon left out of the list must not open from
     * a typed-in address either.
     */
    public function view(User $user, ServiceOrder $serviceOrder): bool
    {
        return ServiceOrder::visibleTo($user)->whereKey($serviceOrder->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('serviceorder.create');
    }

    /**
     * Whether this person may put a task on a werkbon.
     *
     * Its own permission, separate from opening the werkbon: what is actually to
     * be done — which product, how many — is the part somebody works from. Asked
     * before the werkbon is in hand, so it takes no werkbon. Which werkbon this
     * person may reach is a separate question, answered by the scope.
     */
    public function addTask(User $user): bool
    {
        return $user->hasPermission('serviceordertaskinstance.create');
    }

    /** Putting a task on this werkbon: one the person may see, and that is still open. */
    public function addTaskTo(User $user, ServiceOrder $serviceOrder): Response
    {
        if (!$this->addTask($user) || !$this->view($user, $serviceOrder)) {
            return Response::deny();
        }

        return $serviceOrder->is_closed
            ? Response::deny('Deze werkbon is gesloten. Heropen hem eerst als je er nog een taak op wilt zetten.')
            : Response::allow();
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

    /**
     * A closed werkbon only opens again for someone who may reopen it, whatever
     * else they may do with fases: the stepper, the button and the bulk move all
     * ask this.
     */
    public function updateStage(User $user, ServiceOrder $serviceOrder, ServiceOrderStage $stage): bool
    {
        if ($serviceOrder->is_closed && !$stage->closesOrder()) {
            return $this->reopen($user, $serviceOrder);
        }

        if ($user->hasPermission('serviceorder.update')) {
            return true;
        }

        if ($user->hasPermission('serviceorder.close') && $stage->is_closed_state) {
            return true;
        }

        return $user->hasPermission('serviceorder.mark_partially_complete') && $stage->is_incomplete_state;
    }

    public function reopen(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('serviceorder.reopen');
    }

    /**
     * Wissen wat de klant getekend heeft is net zo zwaar als een gesloten bon weer
     * openzetten, dus het hangt aan hetzelfde recht.
     */
    public function clearSignature(User $user, ServiceOrder $serviceOrder): bool
    {
        return $this->reopen($user, $serviceOrder);
    }

    public function exportPdf(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('serviceorder.export_pdf') && $this->view($user, $serviceOrder);
    }

    public function emailPdf(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('serviceorder.email_pdf') && $this->view($user, $serviceOrder);
    }

    public function emailPdfWithChecks(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('serviceorder.email_pdf_with_checks') && $this->view($user, $serviceOrder);
    }

    public function sendToAdministration(User $user, ServiceOrder $serviceOrder): bool
    {
        return $user->hasPermission('integration.send_serviceorder') && $this->view($user, $serviceOrder);
    }

    public function seeFinancials(User $user, ?ServiceOrder $serviceOrder = null): bool
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

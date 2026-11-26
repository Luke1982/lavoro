<?php

namespace App\Policies;

use App\Models\ServiceJob;
use App\Models\User;

class ServiceJobPolicy
{
    /** Reading keuringen at all; which ones follows their werkbon (ServiceJob::scopeVisibleTo). */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('servicejob.read');
    }

    /** This one keuring, by ServiceJob::scopeVisibleTo: with the right to read keuringen on a werkbon this person may open, or on one they are planned for. */
    public function view(User $user, ServiceJob $service_job): bool
    {
        return ServiceJob::visibleTo($user)->whereKey($service_job->id)->exists();
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('servicejob.create');
    }

    /** Filling in, finishing and reopening a keuring: with the right to, on one this person may open. */
    public function update(User $user, ServiceJob $service_job): bool
    {
        return $user->hasPermission('servicejob.update') && $this->view($user, $service_job);
    }

    /** The keuring as a PDF: for whoever may export or mail it, as the buttons on both screens show. */
    public function exportPdf(User $user, ServiceJob $service_job): bool
    {
        return ($user->hasPermission('servicejob.export_pdf') || $user->hasPermission('servicejob.mail_pdf'))
            && $this->view($user, $service_job);
    }

    public function mailPdf(User $user, ServiceJob $service_job): bool
    {
        return $user->hasPermission('servicejob.mail_pdf') && $this->view($user, $service_job);
    }

    /** Also asked of the class, where a paste takes back the keuringen it made on a werkbon it already checked. */
    public function delete(User $user, ?ServiceJob $service_job = null): bool
    {
        return $user->hasPermission('servicejob.delete')
            && ($service_job === null || $this->view($user, $service_job));
    }
}

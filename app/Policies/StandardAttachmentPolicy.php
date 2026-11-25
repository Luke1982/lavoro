<?php

namespace App\Policies;

use App\Models\StandardAttachment;
use App\Models\User;

class StandardAttachmentPolicy
{
    /** Standard attachments are only shown on the page that manages them. */
    public function viewAny(User $user): bool
    {
        return $this->manage($user);
    }

    public function view(User $user, StandardAttachment $standard_attachment): bool
    {
        return $this->viewAny($user);
    }

    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('standardattachment.manage');
    }
}

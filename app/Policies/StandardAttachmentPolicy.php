<?php

namespace App\Policies;

use App\Models\User;

class StandardAttachmentPolicy
{
    public function manage(User $user): bool
    {
        return $user->isAdmin() || $user->hasPermission('standardattachment.manage');
    }
}

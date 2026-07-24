<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\User;

class DocumentPolicy
{
    /**
     * Determine whether the user can see documents.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('document.see');
    }

    /**
     * Determine whether the user can upload documents.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('document.upload');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(User $user, Document $document): bool
    {
        return $user->hasPermission('document.update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(User $user, Document $document): bool
    {
        return $user->hasPermission('document.delete');
    }

    /**
     * Determine whether the user can update documents in bulk, where there is no
     * single model to weigh.
     */
    public function updateAny(User $user): bool
    {
        return $user->hasPermission('document.update');
    }

    /**
     * Determine whether the user can delete documents in bulk, where there is no
     * single model to weigh.
     */
    public function deleteAny(User $user): bool
    {
        return $user->hasPermission('document.delete');
    }
}

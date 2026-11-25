<?php

namespace App\Policies;

use App\Models\Document;
use App\Models\DocumentCategory;
use App\Models\User;

class DocumentCategoryPolicy
{
    /** A category is a label on documents: whoever may see documents may see their labels. */
    public function viewAny(User $user): bool
    {
        return $user->can('viewAny', Document::class);
    }

    public function view(User $user, DocumentCategory $document_category): bool
    {
        return $this->viewAny($user);
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('documentcategory.manage');
    }

    public function update(User $user, DocumentCategory $document_category): bool
    {
        return $user->hasPermission('documentcategory.manage');
    }

    public function delete(User $user, DocumentCategory $document_category): bool
    {
        return $user->hasPermission('documentcategory.manage');
    }
}

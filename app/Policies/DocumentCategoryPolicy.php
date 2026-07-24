<?php

namespace App\Policies;

use App\Models\DocumentCategory;
use App\Models\User;

class DocumentCategoryPolicy
{
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

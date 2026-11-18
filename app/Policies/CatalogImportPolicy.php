<?php

namespace App\Policies;

use App\Enums\CatalogImportKind;
use App\Models\CatalogImport;
use App\Models\User;

/**
 * A spreadsheet import is its uploader's own: someone else may hold the same
 * rights, but the file and the choices made for it are not theirs to change.
 */
class CatalogImportPolicy
{
    public function create(User $user, CatalogImportKind $kind): bool
    {
        return $user->can('import', $kind->modelClass());
    }

    public function view(User $user, CatalogImport $catalog_import): bool
    {
        return $catalog_import->user_id === $user->id
            && $user->can('import', $catalog_import->kind->modelClass());
    }

    public function update(User $user, CatalogImport $catalog_import): bool
    {
        return $this->view($user, $catalog_import);
    }

    public function delete(User $user, CatalogImport $catalog_import): bool
    {
        return $this->view($user, $catalog_import);
    }
}

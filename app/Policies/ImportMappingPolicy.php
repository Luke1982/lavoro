<?php

namespace App\Policies;

use App\Enums\CatalogImportKind;
use App\Models\ImportMapping;
use App\Models\User;

/**
 * Saved mappings are shared by everyone who imports that kind: the next price
 * list from a supplier is often read in by someone else than the last one.
 */
class ImportMappingPolicy
{
    /** Whoever may import any kind may read the mappings of the kinds they import. */
    public function viewAny(User $user): bool
    {
        return ImportMapping::importableKinds($user) !== [];
    }

    public function view(User $user, ImportMapping $import_mapping): bool
    {
        return $user->can('import', $import_mapping->kind->modelClass());
    }

    public function create(User $user, CatalogImportKind $kind): bool
    {
        return $user->can('import', $kind->modelClass());
    }

    public function delete(User $user, ImportMapping $import_mapping): bool
    {
        return $user->can('import', $import_mapping->kind->modelClass());
    }
}

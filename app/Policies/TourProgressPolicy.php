<?php

namespace App\Policies;

use App\Models\User;

/**
 * Everyone who can sign in has a rondleiding of their own and keeps track of it
 * themselves; nobody reads or writes anybody else's.
 *
 * Which chapters somebody is offered is decided here too, from what each chapter
 * says it needs: rights and modules. Whether it is meant for a phone or a large
 * screen only the screen can tell.
 */
class TourProgressPolicy
{
    public function view(User $user): bool
    {
        return true;
    }

    public function record(User $user): bool
    {
        return true;
    }

    /**
     * @param  array{permissions?: array<int, string>, anyPermissions?: array<int, string>, modules?: array<int, string>}  $requires
     */
    public function watch(User $user, array $requires): bool
    {
        foreach ($requires['permissions'] ?? [] as $permission) {
            if (!$user->hasPermission($permission)) {
                return false;
            }
        }

        $any = $requires['anyPermissions'] ?? [];
        if ($any !== [] && !collect($any)->contains(fn (string $permission) => $user->hasPermission($permission))) {
            return false;
        }

        foreach ($requires['modules'] ?? [] as $module) {
            if (!tenancy()->initialized || !tenancy()->tenant->hasModule($module)) {
                return false;
            }
        }

        return true;
    }
}

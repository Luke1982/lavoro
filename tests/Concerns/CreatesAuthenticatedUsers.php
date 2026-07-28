<?php

namespace Tests\Concerns;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;

trait CreatesAuthenticatedUsers
{
    protected function admin(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => 'admin'])->id);

        return $user;
    }

    /**
     * A user holding several permissions at once, for the cases where one
     * permission opens a door and a second decides how far it opens.
     */
    protected function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'role-' . md5(implode('|', $permissions))]);

        foreach ($permissions as $name) {
            $permission = Permission::firstOrCreate(['name' => $name], ['label' => $name]);
            $role->permissions()->syncWithoutDetaching($permission->id);
        }

        $user->roles()->attach($role->id);

        return $user;
    }

    protected function userWith(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(['name' => 'role-' . $permission]);
        $perm = Permission::firstOrCreate(['name' => $permission], ['label' => $permission]);
        $role->permissions()->attach($perm->id);
        $user->roles()->attach($role->id);

        return $user;
    }
}

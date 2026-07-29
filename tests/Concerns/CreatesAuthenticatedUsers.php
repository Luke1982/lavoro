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

    /**
     * The role is reused between calls, so the permission has to be attached only
     * if it is not on there already. Attaching outright meant two users holding
     * the same permission — one asking a question and one who should not see it —
     * failed on a unique key rather than on the thing being tested.
     */
    protected function userWith(string $permission): User
    {
        return $this->userWithPermissions($permission);
    }
}

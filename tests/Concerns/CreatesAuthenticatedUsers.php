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

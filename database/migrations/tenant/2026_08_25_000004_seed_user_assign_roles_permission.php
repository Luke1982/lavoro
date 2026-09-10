<?php

use App\Models\Permission;
use Illuminate\Database\Migrations\Migration;

/**
 * Granting roles was riveted to admin. It is a permission of its own because
 * whoever hands out roles indirectly hands out every permission: that should be
 * separate from ordinary editing of a user.
 */
return new class extends Migration
{
    private array $permissions = [
        ['name' => 'user.assign_roles', 'label' => 'Rollen aan gebruikers toekennen'],
    ];

    public function up(): void
    {
        foreach ($this->permissions as $permission) {
            if (!Permission::where('name', $permission['name'])->exists()) {
                Permission::create($permission);
            }
        }
    }

    public function down(): void
    {
        foreach ($this->permissions as $permission) {
            Permission::where('name', $permission['name'])->delete();
        }
    }
};

<?php

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::where('name', 'google_calendar.connect')->first();
        if (!$permission) {
            return;
        }

        $role_ids = Role::whereHas('permissions', fn ($q) => $q->where('name', 'event.read'))
            ->pluck('id')
            ->all();

        foreach ($role_ids as $role_id) {
            $role = Role::find($role_id);
            if ($role) {
                $role->permissions()->syncWithoutDetaching([$permission->id]);
            }
        }
    }

    public function down(): void
    {
        $permission = Permission::where('name', 'google_calendar.connect')->first();
        if (!$permission) {
            return;
        }

        $role_ids = Role::whereHas('permissions', fn ($q) => $q->where('name', 'event.read'))
            ->pluck('id')
            ->all();

        foreach ($role_ids as $role_id) {
            $role = Role::find($role_id);
            if ($role) {
                $role->permissions()->detach($permission->id);
            }
        }
    }
};

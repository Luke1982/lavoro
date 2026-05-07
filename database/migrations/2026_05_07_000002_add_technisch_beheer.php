<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->upsert([
            [
                'name'       => 'technical.management',
                'label'      => 'Technisch beheer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['name'], ['label', 'updated_at']);

        $permission = DB::table('permissions')->where('name', 'technical.management')->first();

        DB::table('roles')->upsert([
            [
                'name'       => 'technisch beheer',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['name'], ['updated_at']);

        $role = DB::table('roles')->where('name', 'technisch beheer')->first();

        $alreadyLinked = DB::table('permissionables')
            ->where('permission_id', $permission->id)
            ->where('permissionable_type', Role::class)
            ->where('permissionable_id', $role->id)
            ->exists();

        if (!$alreadyLinked) {
            DB::table('permissionables')->insert([
                'permission_id'       => $permission->id,
                'permissionable_type' => Role::class,
                'permissionable_id'   => $role->id,
                'created_at'          => now(),
                'updated_at'          => now(),
            ]);
        }
    }

    public function down(): void
    {
        $permission = DB::table('permissions')->where('name', 'technical.management')->first();
        $role = DB::table('roles')->where('name', 'technisch beheer')->first();

        if ($permission && $role) {
            DB::table('permissionables')
                ->where('permission_id', $permission->id)
                ->where('permissionable_type', Role::class)
                ->where('permissionable_id', $role->id)
                ->delete();
        }

        if ($role) {
            DB::table('roles')->where('id', $role->id)->delete();
        }

        DB::table('permissions')->where('name', 'technical.management')->delete();
    }
};

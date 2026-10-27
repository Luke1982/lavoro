<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        DB::table('permissions')->insertOrIgnore([
            ['name' => 'productable.update', 'label' => 'Productkoppelingen bewerken',
                'created_at' => $now, 'updated_at' => $now],
        ]);

        $permissionId = DB::table('permissions')->where('name', 'productable.update')->value('id');

        $roleNames = ['Binnendienst', 'Administratie'];
        $roleIds = DB::table('roles')->whereIn('name', $roleNames)->pluck('id');

        foreach ($roleIds as $roleId) {
            DB::table('permissionables')->insertOrIgnore([
                'permissionable_type' => 'App\\Models\\Role',
                'permissionable_id'   => $roleId,
                'permission_id'       => $permissionId,
                'created_at'          => $now,
                'updated_at'          => $now,
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')->where('name', 'productable.update')->value('id');

        if ($permissionId) {
            DB::table('permissionables')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};

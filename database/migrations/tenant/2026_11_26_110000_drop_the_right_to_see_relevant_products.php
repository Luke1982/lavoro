<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Whoever sees a machine sees its product, so the right to see the products on
 * one's own open werkbonnen no longer decides anything. It leaves the role editor
 * rather than staying there as a switch that does nothing. Going back puts the
 * right back without the roles it was in.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission_id = DB::table('permissions')->where('name', 'product.read.relevant.serviceorder')->value('id');

        if ($permission_id) {
            DB::table('permissionables')->where('permission_id', $permission_id)->delete();
            DB::table('permissions')->where('id', $permission_id)->delete();
        }
    }

    public function down(): void
    {
        DB::table('permissions')->insertOrIgnore([
            ['name' => 'product.read.relevant.serviceorder', 'label' => 'Producten zien die relevant zijn voor open werkbonnen', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
};

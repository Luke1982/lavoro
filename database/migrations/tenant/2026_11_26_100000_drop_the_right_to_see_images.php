<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Photos follow the record they hang off: whoever may open the record sees them.
 * The right to see photos no longer decides anything, so it leaves the role
 * editor rather than staying there as a switch that does nothing. Going back
 * puts the right back without the roles it was in.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permission_id = DB::table('permissions')->where('name', 'image.see')->value('id');

        if ($permission_id) {
            DB::table('permissionables')->where('permission_id', $permission_id)->delete();
            DB::table('permissions')->where('id', $permission_id)->delete();
        }
    }

    public function down(): void
    {
        DB::table('permissions')->insertOrIgnore([
            ['name' => 'image.see', 'label' => 'Afbeeldingen bekijken', 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
};

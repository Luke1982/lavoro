<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The role 'technisch beheer' and the permission that hung on it go away.
     * What sat behind it -- configuring a customer's integrations -- belongs to
     * MajorLabel itself, and the super admin is there for that now. That is a
     * role the customer cannot see, create or grant.
     *
     * So whoever had the old role loses access to Technisch beheer. That is the
     * intention: they were our own accounts.
     */
    public function up(): void
    {
        $role = DB::table('roles')->where('name', 'technisch beheer')->first();

        if ($role) {
            DB::table('permissionables')
                ->where('permissionable_type', Role::class)
                ->where('permissionable_id', $role->id)
                ->delete();

            DB::table('roleables')->where('role_id', $role->id)->delete();
            DB::table('roles')->where('id', $role->id)->delete();
        }

        $permission = DB::table('permissions')->where('name', 'technical.management')->first();

        if ($permission) {
            DB::table('permissionables')->where('permission_id', $permission->id)->delete();
            DB::table('permissions')->where('id', $permission->id)->delete();
        }
    }

    /**
     * Puts the role and the permission back, empty. Whoever had it does not get
     * it back; that cannot be recorded without keeping the old links, and it is
     * about a handful of our own accounts.
     */
    public function down(): void
    {
        DB::table('permissions')->insertOrIgnore([
            'name' => 'technical.management',
            'label' => 'Technisch beheer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('roles')->insertOrIgnore([
            'name' => 'technisch beheer',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
};

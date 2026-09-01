<?php

use App\Models\Role;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * De rol 'technisch beheer' en het recht dat eraan hing verdwijnen. Wat
     * erachter zat -- de koppelingen van een klant instellen -- hoort bij
     * MajorLabel zelf, en daar is nu de superbeheerder voor. Dat is een rol
     * die de klant niet kan zien, aanmaken of toekennen.
     *
     * Wie de oude rol had verliest dus toegang tot Technisch beheer. Dat is de
     * bedoeling: het waren onze eigen accounts.
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
     * Zet de rol en het recht terug, leeg. Wie hem had krijgt hem niet terug;
     * dat is niet vast te leggen zonder de oude koppelingen te bewaren, en het
     * gaat om een handvol accounts van onszelf.
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

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->upsert([
            [
                'name'        => 'roster.manage_own',
                'label'       => 'Eigen rooster beheren',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
            [
                'name'        => 'roster.manage_all',
                'label'       => 'Rooster van alle gebruikers beheren',
                'created_at'  => now(),
                'updated_at'  => now(),
            ],
        ], ['name'], ['label', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->whereIn('name', ['roster.manage_own', 'roster.manage_all'])
            ->delete();
    }
};

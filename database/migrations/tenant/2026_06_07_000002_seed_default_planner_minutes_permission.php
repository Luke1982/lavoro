<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->upsert([
            [
                'name'       => 'settings.update_default_planner_minutes',
                'label'      => 'Standaard planminuten instellen',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['name'], ['label', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'settings.update_default_planner_minutes')
            ->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')
            ->where('name', 'settings.update_default_planner_minutes')
            ->update([
                'name'       => 'planner.manage_settings',
                'label'      => 'Planner-instellingen beheren',
                'updated_at' => now(),
            ]);
    }

    public function down(): void
    {
        DB::table('permissions')
            ->where('name', 'planner.manage_settings')
            ->update([
                'name'       => 'settings.update_default_planner_minutes',
                'label'      => 'Standaard planminuten instellen',
                'updated_at' => now(),
            ]);
    }
};

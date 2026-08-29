<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('permissions')->upsert([
            [
                'name' => 'projects.lead',
                'label' => 'Mag projecten leiden',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['name'], ['label', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'projects.lead')->delete();
    }
};

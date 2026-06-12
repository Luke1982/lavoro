<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->upsert([
            [
                'name'       => 'location.track',
                'label'      => 'Locatie tracking',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ], ['name'], ['label', 'updated_at']);

        DB::table('general_settings')->upsert([
            ['key' => 'location_tracking_start', 'value' => '07:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'location_tracking_end',   'value' => '18:00', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'location_tracking_days',  'value' => '1,2,3,4,5', 'created_at' => now(), 'updated_at' => now()],
        ], ['key'], ['value', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('permissions')->where('name', 'location.track')->delete();
        DB::table('general_settings')
            ->whereIn('key', ['location_tracking_start', 'location_tracking_end', 'location_tracking_days'])
            ->delete();
    }
};

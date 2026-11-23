<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * These appointment facts also land on werkbon and customer timelines, where a
 * monteur could read who goes where and when on appointments the planner hides
 * from them. They are now read under the permission for other people's
 * appointments; this gives the lines already written the same gate.
 */
return new class extends Migration
{
    private const EVENT_KEYS = [
        'appointment.crew_changed',
        'appointment.customer_changed',
        'appointment.location_deleted',
        'appointment.service_order_changed',
        'appointment.google_change_reverted',
    ];

    public function up(): void
    {
        DB::table('activities')
            ->whereIn('event_key', self::EVENT_KEYS)
            ->whereNull('required_permission')
            ->update(['required_permission' => 'event.see_all']);
    }

    public function down(): void {}
};

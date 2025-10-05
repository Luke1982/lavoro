<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $rows = [
            [
                'name' => 'dashboard.see_stats',
                'label' => 'Dashboard statistieken bekijken',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'dashboard.see_events',
                'label' => 'Afspraken op dashboard bekijken',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'dashboard.see_open_serviceorders.not_sent',
                'label' => 'Open werkbonnen (niet verzonden) bekijken',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'dashboard.see_open_serviceorders.sent_administration',
                'label' => 'Open werkbonnen (verzonden naar administratie) bekijken',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'dashboard.see_open_serviceorders.sent_customer',
                'label' => 'Open werkbonnen (verzonden naar klant) bekijken',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'dashboard.see_open_serviceorders.all',
                'label' => 'Alle open werkbonnen bekijken',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'dashboard.see_upcoming_servicejobs',
                'label' => 'Aankomende periodieke controles bekijken',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'dashboard.see_pending_tickets',
                'label' => 'Openstaande tickets bekijken',
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'name' => 'dashboard.see_map',
                'label' => 'Kaart bekijken',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ];

        DB::table('permissions')->upsert($rows, ['name'], ['label', 'updated_at']);
    }

    public function down(): void
    {
        $names = [
            'dashboard.see_stats',
            'dashboard.see_events',
            'dashboard.see_open_serviceorders.not_sent',
            'dashboard.see_open_serviceorders.sent_administration',
            'dashboard.see_open_serviceorders.sent_customer',
            'dashboard.see_open_serviceorders.all',
            'dashboard.see_upcoming_servicejobs',
            'dashboard.see_pending_tickets',
            'dashboard.see_map',
        ];

        DB::table('permissions')->whereIn('name', $names)->delete();
    }
};

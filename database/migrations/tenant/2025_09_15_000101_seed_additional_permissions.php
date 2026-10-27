<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $resources = [
            'customer' => 'Klant',
            'product' => 'Product',
            'producttype' => 'Producttype',
            'brand' => 'Merk',
            'asset' => 'Machine',
            'ticket' => 'Storing',
            'servicecheck' => 'Controlepunt',
            'servicecheckgroup' => 'Controlepuntgroep',
            'material' => 'Materiaal',
            'materialusageunit' => 'Materiaal gebruikseenheid',
            'materialcategory' => 'Materiaalcategorie',
            'event' => 'Afspraak',
            'eventtype' => 'Afspraaktype',
            'servicejob' => 'Periodieke controle',
            'serviceorder' => 'Werkbon',
        ];

        $actions = [
            'read' => 'zien',
            'create' => 'aanmaken',
            'update' => 'bijwerken',
            'delete' => 'verwijderen',
        ];

        $now = now();
        $rows = [];

        foreach ($resources as $key => $label_base) {
            foreach ($actions as $action => $verb) {
                $rows[] = [
                    'name' => "{$key}.{$action}",
                    'label' => "{$label_base} {$verb}",
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        $rows[] = [
            'name' => 'activitylist.read',
            'label' => 'Activiteitenlijst bekijken',
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('permissions')->upsert($rows, ['name'], ['label', 'updated_at']);
    }

    public function down(): void
    {
        $resources = [
            'customer',
            'product',
            'producttype',
            'brand',
            'asset',
            'ticket',
            'servicecheck',
            'servicecheckgroup',
            'material',
            'materialusageunit',
            'materialcategory',
            'event',
            'eventtype',
        ];

        $actions = ['create', 'update', 'delete'];

        $names = [];
        foreach ($resources as $key) {
            foreach ($actions as $action) {
                $names[] = "{$key}.{$action}";
            }
        }
        $names[] = 'activitylist.read';

        DB::table('permissions')->whereIn('name', $names)->delete();
    }
};

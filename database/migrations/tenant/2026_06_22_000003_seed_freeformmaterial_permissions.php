<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $rows = [
            ['name' => 'freeformmaterial.read',   'label' => 'Vrije materiaalregels zien'],
            ['name' => 'freeformmaterial.create', 'label' => 'Vrije materiaalregel toevoegen'],
            ['name' => 'freeformmaterial.update', 'label' => 'Vrije materiaalregel bijwerken'],
            ['name' => 'freeformmaterial.delete', 'label' => 'Vrije materiaalregel verwijderen'],
        ];

        foreach ($rows as &$row) {
            $row['created_at'] = $now;
            $row['updated_at'] = $now;
        }

        DB::table('permissions')->upsert($rows, ['name'], ['label', 'updated_at']);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', [
            'freeformmaterial.read',
            'freeformmaterial.create',
            'freeformmaterial.update',
            'freeformmaterial.delete',
        ])->delete();
    }
};

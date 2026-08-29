<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $rows = [
            ['name' => 'materiable.read.serviceorder',   'label' => 'Materialen op werkbon zien'],
            ['name' => 'materiable.create.serviceorder', 'label' => 'Materiaal aan werkbon koppelen'],
            ['name' => 'materiable.update.serviceorder', 'label' => 'Materiaal op werkbon bijwerken'],
            ['name' => 'materiable.delete.serviceorder', 'label' => 'Materiaal van werkbon verwijderen'],
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
            'materiable.read.serviceorder',
            'materiable.create.serviceorder',
            'materiable.update.serviceorder',
            'materiable.delete.serviceorder',
        ])->delete();
    }
};

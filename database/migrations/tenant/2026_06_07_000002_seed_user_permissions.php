<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        $now = now();
        $rows = [
            ['name' => 'user.read',   'label' => 'Gebruikers zien'],
            ['name' => 'user.create', 'label' => 'Gebruiker aanmaken'],
            ['name' => 'user.update', 'label' => 'Gebruiker bewerken'],
            ['name' => 'user.delete', 'label' => 'Gebruiker verwijderen'],
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
            'user.read',
            'user.create',
            'user.update',
            'user.delete',
        ])->delete();
    }
};

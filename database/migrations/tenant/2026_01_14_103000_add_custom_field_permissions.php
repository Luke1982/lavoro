<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    public function up(): void
    {
        $now = Carbon::now();
        DB::table('permissions')->insert([
            ['name' => 'customfield.read', 'label' => 'Extra velden bekijken', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'customfield.create', 'label' => 'Extra velden aanmaken', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'customfield.update', 'label' => 'Extra velden bewerken', 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'customfield.delete', 'label' => 'Extra velden verwijderen', 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', [
            'customfield.read',
            'customfield.create',
            'customfield.update',
            'customfield.delete',
        ])->delete();
    }
};

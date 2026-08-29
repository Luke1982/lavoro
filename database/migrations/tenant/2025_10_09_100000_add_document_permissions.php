<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('permissions')->insert([
            ['name' => 'document.upload', 'label' => 'Documenten uploaden', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ['name' => 'document.delete', 'label' => 'Documenten verwijderen', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
            ['name' => 'document.see', 'label' => 'Documenten bekijken', 'created_at' => Carbon::now(), 'updated_at' => Carbon::now()],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('permissions')->whereIn('name', [
            'document.upload',
            'document.delete',
            'document.see',
        ])->delete();
    }
};

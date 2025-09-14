<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('label');
            $table->timestamps();
        });

        // Seed a baseline set of permissions (not editable via frontend)
        DB::table('permissions')->insert([
            [
                'name' => 'serviceorder.create',
                'label' => 'Werkbon aanmaken',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'servicejob.create',
                'label' => 'Keuring aanmaken',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};

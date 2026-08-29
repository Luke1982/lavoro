<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('color')->default('blue');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });

        $now = Carbon::now();

        DB::table('document_categories')->insert([
            ['name' => 'Facturen', 'color' => 'amber', 'order' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Contracten', 'color' => 'green', 'order' => 2, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Offertes', 'color' => 'purple', 'order' => 3, 'created_at' => $now, 'updated_at' => $now],
            ['name' => 'Overig', 'color' => 'blue', 'order' => 4, 'created_at' => $now, 'updated_at' => $now],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('document_categories');
    }
};

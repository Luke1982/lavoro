<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('materiables', function (Blueprint $table) {
            $table->dropForeign(['material_role_id']);
            $table->dropColumn('material_role_id');
        });

        Schema::dropIfExists('material_roles');
    }

    public function down(): void
    {
        Schema::create('material_roles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('materiables', function (Blueprint $table) {
            $table->foreignId('material_role_id')
                ->nullable()
                ->default(null)
                ->constrained('material_roles')
                ->nullOnDelete();
        });
    }
};

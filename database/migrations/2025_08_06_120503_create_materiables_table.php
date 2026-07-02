<?php

use App\Models\Material;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('materiables', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Material::class)->constrained();
            $table->morphs('materiable');
            $table->foreignId('material_role_id')
                ->nullable()
                ->default(null)
                ->constrained('material_roles')
                ->nullOnDelete();
            $table->decimal('quantity', 10, 2)->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('materiables');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->morphs('suppliable');
            $table->string('article_number')->nullable();
            $table->boolean('is_preferred')->default(false);
            $table->timestamps();
            $table->unique(['supplier_id', 'suppliable_type', 'suppliable_id'], 'suppliables_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliables');
    }
};

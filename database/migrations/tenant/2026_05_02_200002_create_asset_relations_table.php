<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('asset_relations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_asset_id')
                ->constrained('assets')
                ->cascadeOnDelete();
            $table->foreignId('child_asset_id')
                ->constrained('assets')
                ->cascadeOnDelete();
            $table->foreignId('productable_id')
                ->nullable()
                ->nullOnDelete()
                ->constrained('productables');
            $table->foreignId('product_relation_id')
                ->nullable()
                ->nullOnDelete()
                ->constrained('product_relations');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_relations');
    }
};

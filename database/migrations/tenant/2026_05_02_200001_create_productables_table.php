<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('productables', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->string('productable_type');
            $table->unsignedBigInteger('productable_id');
            $table->foreignId('product_relation_id')
                ->nullable()
                ->nullOnDelete()
                ->constrained('product_relations');
            $table->unsignedInteger('quantity')->default(1);
            $table->boolean('is_required')->default(false);
            $table->timestamps();

            $table->index(['productable_type', 'productable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('productables');
    }
};

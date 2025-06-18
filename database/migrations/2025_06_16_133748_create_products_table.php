<?php

use App\Models\ProductType;
use App\Enums\ProductBrands;
use App\Models\Brand;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProductType::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(Brand::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->string('model');
            $table->string('description')->nullable();
            $table->date('start_sell')->nullable();
            $table->date('end_sell')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};

<?php

use App\Enums\ProductTypes;
use App\Enums\ProductBrands;
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
            $table->enum(
                'type',
                array_map(
                    fn($case) => $case->value,
                    ProductTypes::cases()
                )
            )->nullable();
            $table->enum(
                'brand',
                array_map(
                    fn($case) => $case->value,
                    ProductBrands::cases()
                )
            );
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

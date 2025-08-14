<?php

use App\Enums\ProductTypes;
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
        Schema::create('product_types', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique()->required();
            $table->integer('typical_certificate_days')->nullable()->default(null);
            $table->timestamps();
        });

        array_map(
            fn($case) => \App\Models\ProductType::create(['name' => $case->value]),
            ProductTypes::cases()
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_types');
    }
};

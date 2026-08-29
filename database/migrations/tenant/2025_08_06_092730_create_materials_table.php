<?php

use App\Models\MaterialCategory;
use App\Models\MaterialUsageUnit;
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
        Schema::create('materials', function (Blueprint $table) {
            $table->id();
            $table->uuid('snelstart_id')->nullable()->unique();
            $table->string('name');
            $table->string('description')->nullable();
            $table->foreignIdFor(MaterialCategory::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('code')->nullable()->unique();
            $table->string('vendor_code')->nullable();
            $table->decimal('price', 10, 2)->default(0.00)->nullable();
            $table->decimal('cost_price', 10, 2)->default(0.00)->nullable();
            $table->foreignIdFor(MaterialUsageUnit::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->boolean('divisable')->default(false);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_service')->default(false);
            $table->decimal('stock', 10, 2)->default(0.00);
            $table->decimal('min_stock', 10, 2)->default(0.00);
            $table->decimal('max_stock', 10, 2)->default(0.00);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('materials');
    }
};

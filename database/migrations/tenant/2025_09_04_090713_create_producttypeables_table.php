<?php

use App\Models\ProductType;
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
        Schema::create('producttypeables', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProductType::class)
                ->constrained();
            $table->morphs('producttypeable');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('producttypeables');
    }
};

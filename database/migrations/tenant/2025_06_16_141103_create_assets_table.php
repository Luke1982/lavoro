<?php

use App\Models\Product;
use App\Models\Customer;
use App\Enums\AssetStatusses;
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
        Schema::create('assets', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Product::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->foreignIdFor(Customer::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->string('serial_number')->required();
            $table->date('next_service_date')->nullable();
            $table->enum('status', AssetStatusses::valueArray())
                ->default(AssetStatusses::valueArray()[0]);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assets');
    }
};

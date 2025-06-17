<?php

use App\Models\Customer;
use App\Models\Product;
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
            $table->enum('status', ['Actief', 'Niet actief'])
                ->default('Actief');
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

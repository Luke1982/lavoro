<?php

use App\Models\Customer;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_contracts', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Customer::class)->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            $table->date('start_date');
            $table->date('end_date')->nullable();
            $table->decimal('price', 10, 2);
            $table->string('price_interval');
            $table->unsignedInteger('price_interval_days')->nullable();
            $table->boolean('manage_frequency_per_asset')->default(false);
            $table->string('frequency')->nullable();
            $table->unsignedInteger('frequency_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_contracts');
    }
};

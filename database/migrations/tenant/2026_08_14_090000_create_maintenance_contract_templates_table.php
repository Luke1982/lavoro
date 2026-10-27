<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('maintenance_contract_templates', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('title')->nullable();
            $table->unsignedSmallInteger('duration_months')->nullable();
            $table->decimal('price', 10, 2)->nullable();
            $table->string('price_interval')->nullable();
            $table->unsignedInteger('price_interval_days')->nullable();
            $table->boolean('manage_frequency_per_asset')->default(false);
            $table->string('frequency')->nullable();
            $table->unsignedInteger('frequency_days')->nullable();
            $table->boolean('auto_generate')->default(false);
            $table->string('auto_generate_interval')->nullable();
            $table->unsignedInteger('auto_generate_interval_days')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('maintenance_contract_templates');
    }
};

<?php

use App\Models\ProductType;
use App\Enums\ServiceCheckTypes;
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
        Schema::create('service_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ProductType::class)
                ->constrained()
                ->cascadeOnDelete();
            $table->string('name')->required();
            $table->integer('order')->default(0);
            $table->enum(
                'type',
                array_map(
                    fn($type) => $type->name,
                    ServiceCheckTypes::cases()
                )
            )->default('radio');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('service_checks');
    }
};

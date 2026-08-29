<?php

use App\Models\Asset;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assetables', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(Asset::class)->constrained()->cascadeOnDelete();
            $table->morphs('assetable');
            $table->string('frequency')->nullable();
            $table->unsignedInteger('frequency_days')->nullable();
            $table->timestamps();

            $table->unique(['asset_id', 'assetable_type', 'assetable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assetables');
    }
};

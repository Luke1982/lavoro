<?php

use App\Models\ServiceCheckInstance;
use App\Models\ServiceCheckValue;
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
        Schema::create('check_instance_service_value', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(ServiceCheckValue::class)
                ->constrained();
                $table->foreignIdFor(ServiceCheckInstance::class)
                ->constrained();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('check_instance_service_value');
    }
};

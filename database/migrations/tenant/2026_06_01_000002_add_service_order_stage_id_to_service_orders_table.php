<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreignId('service_order_stage_id')
                ->nullable()
                ->constrained('service_order_stages')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_order_stage_id');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_order_stages', function (Blueprint $table) {
            $table->boolean('is_planned_state')->default(false);
            $table->boolean('is_closed_state')->default(false);
            $table->boolean('is_plannable_state')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('service_order_stages', function (Blueprint $table) {
            $table->dropColumn(['is_planned_state', 'is_closed_state', 'is_plannable_state']);
        });
    }
};

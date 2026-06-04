<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_stages', function (Blueprint $table) {
            $table->boolean('is_planning_cancelled_state')->default(false)->after('is_plannable_state');
        });
    }

    public function down(): void
    {
        Schema::table('service_order_stages', function (Blueprint $table) {
            $table->dropColumn('is_planning_cancelled_state');
        });
    }
};

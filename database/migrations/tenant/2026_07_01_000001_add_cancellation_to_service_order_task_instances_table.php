<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_task_instances', function (Blueprint $table) {
            $table->boolean('is_cancelled')->default(false)->after('signed_at');
            $table->text('cancellation_reason')->nullable()->after('is_cancelled');
        });
    }

    public function down(): void
    {
        Schema::table('service_order_task_instances', function (Blueprint $table) {
            $table->dropColumn(['is_cancelled', 'cancellation_reason']);
        });
    }
};

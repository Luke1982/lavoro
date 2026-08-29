<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->foreignId('service_order_task_instance_id')
                ->nullable()
                ->after('customer_id')
                ->constrained('service_order_task_instances')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeignIdFor(\App\Models\ServiceOrderTaskInstance::class);
            $table->dropColumn('service_order_task_instance_id');
        });
    }
};

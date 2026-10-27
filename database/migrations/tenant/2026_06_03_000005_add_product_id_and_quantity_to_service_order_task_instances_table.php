<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_order_task_instances', function (Blueprint $table) {
            $table->foreignId('product_id')
                ->nullable()
                ->after('service_order_task_id')
                ->constrained()
                ->nullOnDelete();

            $table->unsignedSmallInteger('quantity')
                ->default(1)
                ->after('product_id');
        });
    }

    public function down(): void
    {
        Schema::table('service_order_task_instances', function (Blueprint $table) {
            $table->dropForeign(['product_id']);
            $table->dropColumn(['product_id', 'quantity']);
        });
    }
};

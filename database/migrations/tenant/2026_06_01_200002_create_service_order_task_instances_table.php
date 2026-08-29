<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('service_order_task_instances', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(\App\Models\ServiceOrder::class)->constrained()->cascadeOnDelete();
            $table->foreignIdFor(\App\Models\ServiceOrderTask::class)
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_complete')->default(false);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('service_order_task_instances');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->foreignId('parent_service_job_id')
                ->nullable()
                ->after('service_order_id')
                ->nullOnDelete()
                ->constrained('service_jobs');
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropForeign(['parent_service_job_id']);
            $table->dropColumn('parent_service_job_id');
        });
    }
};

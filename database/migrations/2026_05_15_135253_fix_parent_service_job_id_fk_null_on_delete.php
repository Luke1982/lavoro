<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropForeign(['parent_service_job_id']);
            $table->foreign('parent_service_job_id')
                ->references('id')->on('service_jobs')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('service_jobs', function (Blueprint $table) {
            $table->dropForeign(['parent_service_job_id']);
            $table->foreign('parent_service_job_id')
                ->references('id')->on('service_jobs')
                ->restrictOnDelete();
        });
    }
};

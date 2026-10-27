<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Requests to create or clean up a tenant.
     *
     * The admin panel runs as lavoro_app and that account may deliberately not
     * create databases; only lavoro_provisioner may, and it hangs on a Linux
     * user of its own. So the panel puts a request down and a worker that does
     * run as the provisioner carries it out. This table is that request, and at
     * the same time the place where a failure is visible -- otherwise someone
     * clicks "create" and nothing happens, quietly.
     */
    public function up(): void
    {
        Schema::connection('central')->create('tenant_provisioning_requests', function (Blueprint $table) {
            $table->id();
            $table->string('action');
            $table->string('status')->default('queued');
            $table->string('tenant_id')->nullable();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('package_key')->nullable();
            $table->json('modules')->nullable();
            $table->text('error')->nullable();
            $table->string('generated_password')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('tenant_provisioning_requests');
    }
};

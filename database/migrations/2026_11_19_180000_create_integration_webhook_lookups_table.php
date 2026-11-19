<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An outside system calling a webhook brings no session, so the only thing
 * that can point at the customer is the token in the address. That token is
 * looked up here, by its hash, and never read from the request as a tenant id.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->create('integration_webhook_lookups', function (Blueprint $table) {
            $table->char('token_hash', 64)->primary();
            $table->string('tenant_id');
            $table->unsignedBigInteger('integration_webhook_id');
            $table->timestamp('created_at')->nullable();

            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('central')->dropIfExists('integration_webhook_lookups');
    }
};

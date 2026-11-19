<?php

use App\Models\IntegrationConnection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The tables behind every link with an outside system: which ones this customer
 * uses, which record here is which record there, and what happened each time
 * they were synchronised. See docs/development/integrations.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('integration_connections', function (Blueprint $table) {
            $table->id();
            $table->string('connector')->unique();
            $table->text('credentials')->nullable();
            $table->json('settings')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_synced_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('last_error_at')->nullable();
            $table->timestamps();
        });

        Schema::create('integrationables', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(IntegrationConnection::class)->constrained()->cascadeOnDelete();
            $table->morphs('integrationable');
            $table->string('external_id');
            $table->text('local_fingerprints')->nullable();
            $table->text('remote_fingerprints')->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('missing_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['integration_connection_id', 'integrationable_type', 'integrationable_id'],
                'integrationables_record_unique',
            );
            $table->unique(
                ['integration_connection_id', 'integrationable_type', 'external_id'],
                'integrationables_external_unique',
            );
        });

        Schema::create('integration_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(IntegrationConnection::class)->constrained()->cascadeOnDelete();
            $table->string('area');
            $table->string('direction');
            $table->nullableMorphs('subject');
            $table->string('status');
            $table->json('counts')->nullable();
            $table->text('message')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();

            $table->index(['integration_connection_id', 'started_at']);
        });

        Schema::create('integration_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignIdFor(IntegrationConnection::class)->constrained()->cascadeOnDelete();
            $table->string('area');
            $table->string('external_id')->nullable();
            $table->text('token');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['integration_connection_id', 'area']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('integration_webhooks');
        Schema::dropIfExists('integration_runs');
        Schema::dropIfExists('integrationables');
        Schema::dropIfExists('integration_connections');
    }
};

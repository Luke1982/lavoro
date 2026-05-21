<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_synced_calendars', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_calendar_integration_id')
                ->constrained('google_calendar_integrations')
                ->cascadeOnDelete();
            $table->foreignId('owner_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('google_calendar_id');
            $table->string('summary');
            $table->text('sync_token')->nullable();
            $table->uuid('watch_channel_id')->nullable();
            $table->string('watch_channel_token')->nullable();
            $table->string('watch_resource_id')->nullable();
            $table->dateTime('watch_expires_at')->nullable();
            $table->dateTime('last_full_sync_at')->nullable();
            $table->timestamps();
            $table->unique(['google_calendar_integration_id', 'owner_user_id'], 'gsc_integration_owner_unique');
            $table->index('watch_channel_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_synced_calendars');
    }
};

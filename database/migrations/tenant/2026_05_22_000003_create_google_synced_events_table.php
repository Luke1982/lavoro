<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('google_synced_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('google_synced_calendar_id')
                ->constrained('google_synced_calendars')
                ->cascadeOnDelete();
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('google_event_id');
            $table->string('etag');
            $table->dateTime('last_pushed_at');
            $table->timestamps();
            $table->unique(['google_synced_calendar_id', 'event_id'], 'gse_cal_event_unique');
            $table->unique(['google_synced_calendar_id', 'google_event_id'], 'gse_cal_googleid_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('google_synced_events');
    }
};

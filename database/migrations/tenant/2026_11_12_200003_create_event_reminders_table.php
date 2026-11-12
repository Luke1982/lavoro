<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which start of which appointment someone has been reminded of.
 *
 * The start is part of the key: an appointment that moves is a new moment to be
 * reminded of, the same moment twice is not. The unique index is what makes that
 * hold when two runs overlap, not a check beforehand. The start has an index of
 * its own because every round splits the rows into those still ahead and those
 * passed, which it prunes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_reminders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->dateTime('starts_at');
            $table->timestamps();

            $table->unique(['event_id', 'user_id', 'starts_at'], 'event_reminders_unique');
            $table->index('starts_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reminders');
    }
};

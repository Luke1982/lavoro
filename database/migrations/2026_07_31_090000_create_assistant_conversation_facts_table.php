<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a conversation has established, so it stops coming loose.
 *
 * A model holds the thread in prose and prose drifts: told a customer had one
 * open werkbon, #4, it later looked up customer #4 and carried on with an
 * entirely different company for the rest of the conversation. Every number in
 * this application is a bare integer, and nothing in a transcript says which
 * table one came from.
 *
 * So the facts are kept here instead of being remembered. Written from what the
 * tools actually returned, read back in on the next question.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_conversation_facts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->uuid('conversation_id');
            $table->json('facts');
            $table->timestamps();

            /** One row per conversation, and it belongs to whoever held it. */
            $table->unique(['user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_conversation_facts');
    }
};

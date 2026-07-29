<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was asked and what came back.
 *
 * Until now none of it was kept: the thread lived in the browser tab and a
 * refresh lost it. That was fine while the assistant only read things, and stops
 * being fine the moment it can make an appointment — the audit could show that
 * create_event ran with a date and a mechanic, and not a word about what anybody
 * asked for that led to it.
 *
 * The rows are small next to what is already stored per tool call, so the reason
 * to prune is age rather than size: a transcript of somebody's working day is
 * worth keeping for a few months and not for ever.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('question');
            $table->text('answer')->nullable();

            /** What went wrong, when nothing came back. */
            $table->string('failure')->nullable();

            /** The page it was asked from, for reading an old answer back in context. */
            $table->string('page')->nullable();

            /** Which tools ran, so an answer can be weighed without the full audit. */
            $table->json('tools')->nullable();

            $table->unsignedSmallInteger('rounds')->default(0);
            $table->unsignedBigInteger('cost_micros')->default(0);
            $table->timestamps();

            /** Every read of this is "mine, newest first". */
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_questions');
    }
};

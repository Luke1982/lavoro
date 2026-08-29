<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Questions worth asking again, offered where they make sense.
 *
 * Half of what this assistant is good at is invisible: nobody guesses that it
 * can read a typeplaatje off the photos already on a werkbon, so nobody asks.
 * A short list on the page somebody is already looking at turns the box from a
 * blank prompt into a menu.
 *
 * The page pattern decides where a question shows up, so "welke machines
 * missen nog gegevens" appears on a werkbon and not on the planner. Questions
 * without an owner are the ones we ship; a user's own are theirs alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_prompts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('label');
            $table->text('question');
            /**
             * Which pages this belongs on, as a path pattern: "serviceorders.show",
             * "products.index", or null for everywhere. Kept as a string rather
             * than a route name so a page that has no named route still matches.
             */
            $table->string('context')->nullable();
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();

            $table->index(['context', 'sort']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_prompts');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks the turns nobody typed.
 *
 * Confirming something asks the assistant to carry on, and that turn was not
 * being written down at all — so the record showed the question that led to a
 * werkbon and nothing about the turn that then proposed the task and had it
 * carried out. That is precisely the half worth keeping.
 *
 * Kept apart rather than mixed in, because the panel answers "what did I ask" and
 * nobody asked these.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->boolean('is_continuation')->default(false)->after('question');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->dropColumn('is_continuation');
        });
    }
};

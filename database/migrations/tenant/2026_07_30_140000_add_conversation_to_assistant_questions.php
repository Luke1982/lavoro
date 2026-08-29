<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ties the turns of one conversation together.
 *
 * They were stored as loose questions, so the panel listed "eerste", "Nee het is
 * de eerste klant" and "Ja verzet die bon 146 naar morgen" as three unrelated
 * things — when they are one conversation, and two of them are meaningless on
 * their own. There was also no way back into a thread: clicking put an old
 * question in the box and left the rest behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->uuid('conversation_id')->nullable()->after('user_id');

            /** Every read is "my conversations, most recent first". */
            $table->index(['user_id', 'conversation_id']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'conversation_id']);
            $table->dropColumn('conversation_id');
        });
    }
};

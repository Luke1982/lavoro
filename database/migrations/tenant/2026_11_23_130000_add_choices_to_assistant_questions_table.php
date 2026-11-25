<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The choices a turn put in front of somebody, so a conversation opened again
 * from Gesprekken shows them instead of an answer that ends in "kies hieronder"
 * with nothing below it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->json('choices')->nullable()->after('tools');
        });
    }

    public function down(): void
    {
        Schema::table('assistant_questions', function (Blueprint $table) {
            $table->dropColumn('choices');
        });
    }
};

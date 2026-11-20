<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What the customer signed, frozen with the yes: the send as it went out, with
 * the options and alternatives they chose. The signed copy, the version's PDF
 * and any dispute later read this, not the quote as it stands.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->json('accepted_document')->nullable()->after('accepted_version_id');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('accepted_document');
        });
    }
};

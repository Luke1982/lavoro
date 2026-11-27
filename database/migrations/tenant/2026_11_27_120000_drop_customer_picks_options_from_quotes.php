<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the customer may tick the optional lines was a setting per quote. A
 * line made optional is one the customer decides on, so they always may.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('customer_picks_options');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->boolean('customer_picks_options')->default(true)->after('closing_text');
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who gets an announcement is a link between a user and a record, so a row in
 * userables. That this user acknowledged it is not a second link but a property
 * of the same one: the moment they did.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('userables', function (Blueprint $table) {
            $table->dateTime('acknowledged_at')->nullable()->after('diverging_end');
        });
    }

    public function down(): void
    {
        Schema::table('userables', function (Blueprint $table) {
            $table->dropColumn('acknowledged_at');
        });
    }
};

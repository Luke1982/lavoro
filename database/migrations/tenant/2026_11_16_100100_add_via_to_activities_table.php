<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How the person did it, when that is worth knowing afterwards: 'tray' when the
 * change came from pasting something off the bakje. Null for everything done
 * the ordinary way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('via', 20)->nullable()->after('actor_name');
        });
    }

    public function down(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->dropColumn('via');
        });
    }
};

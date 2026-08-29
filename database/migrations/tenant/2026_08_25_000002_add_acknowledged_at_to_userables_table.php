<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wie een aankondiging krijgt is een koppeling tussen een gebruiker en een
 * record, dus een rij in userables. Dat die gebruiker bevestigd heeft is geen
 * tweede koppeling maar een eigenschap van dezelfde: het moment waarop.
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

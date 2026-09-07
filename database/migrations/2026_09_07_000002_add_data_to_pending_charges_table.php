<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Waar een openstaande post vandaan komt, naast het bedrag.
 *
 * Een verrekening groeit: wie een module aanzet en daarna de prijs afspreekt,
 * doet twee wijzigingen die op een regel horen te belanden. Om die regel te
 * kunnen blijven omschrijven -- van welk pakket naar welk, van welk bedrag
 * naar welk -- moet er meer bewaard worden dan de zin die er nu staat.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('pending_charges', function (Blueprint $table) {
            $table->json('data')->nullable()->after('amount_cents');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('pending_charges', fn (Blueprint $t) => $t->dropColumn('data'));
    }
};

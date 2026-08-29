<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Een opmerking hoeft niet meer van een gebruiker te zijn.
 *
 * Een klant die via een aanleverlink iets toelicht heeft geen account en krijgt er
 * ook geen. De naam wordt overgeschreven en niet als verwijzing bewaard, net als
 * bij de tijdlijn: een klant die later anders gaat heten mag niet met terugwerkende
 * kracht iets anders gezegd hebben.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('remarks', function (Blueprint $table) {
            $table->string('author_name')->nullable()->after('user_id');
        });

        Schema::table('remarks', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable()->change();
        });
    }

    /**
     * Terug kan alleen als er geen opmerkingen van klanten meer staan: die hebben
     * geen gebruiker en passen niet in de oude kolom.
     *
     * Ze worden hier niet weggegooid. Een abonnement is een voorkeur die je zo
     * opnieuw aanzet, maar dit is wat een klant geschreven heeft over zijn eigen
     * storing, en dat verdwijnt niet als bijvangst van een schemawijziging. Wie
     * terug wil krijgt te horen wat hem in de weg staat.
     */
    public function down(): void
    {
        $from_customers = DB::table('remarks')->whereNull('user_id')->count();

        if ($from_customers > 0) {
            throw new RuntimeException(
                'Er staan ' . $from_customers . ' opmerkingen van klanten zonder gebruiker. '
                . 'Wijs ze toe aan een gebruiker of verwijder ze voordat je deze migratie terugdraait.'
            );
        }

        Schema::table('remarks', function (Blueprint $table) {
            $table->dropColumn('author_name');
        });

        Schema::table('remarks', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }
};

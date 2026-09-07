<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vanaf wanneer de huidige betaaltermijn loopt.
 *
 * De periodes werden altijd vanaf de ingangsdatum geteld. Stapte een klant
 * halverwege het jaar over van maand naar jaar, dan liep de jaarperiode vanaf
 * januari -- een periode waarvoor in januari al een maandfactuur was gestuurd,
 * dus gold het jaar als betaald en kreeg de klant de rest van het jaar gratis.
 * De termijn krijgt daarom zijn eigen begindatum, los van de ingangsdatum die
 * op het scherm staat.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->date('billing_period_started_on')->nullable()->after('billing_period');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', fn (Blueprint $t) => $t->dropColumn('billing_period_started_on'));
    }
};

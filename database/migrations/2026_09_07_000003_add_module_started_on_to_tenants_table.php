<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Wanneer een module is aangezet, per module.
 *
 * Zonder die datum valt niet uit te rekenen hoeveel van de lopende maand een
 * klant de module gehad heeft, en betaalt hij een hele maand voor iets dat hij
 * op de zevende erbij nam.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->json('module_started_on')->nullable()->after('module_prices');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', fn (Blueprint $t) => $t->dropColumn('module_started_on'));
    }
};

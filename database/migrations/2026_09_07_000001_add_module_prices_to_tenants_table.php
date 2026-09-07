<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Een eigen prijs per module, per klant.
 *
 * Als sleutel-waardepaar en niet als tabel: het staat naast de modules zelf,
 * die ook als lijstje in een json-kolom op de klant staan, en het hoort bij
 * niets anders dan die klant.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->json('module_prices')->nullable()->after('modules');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', fn (Blueprint $t) => $t->dropColumn('module_prices'));
    }
};

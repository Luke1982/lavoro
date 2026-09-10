<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A price of its own per module, per customer.
 *
 * As a key-value pair and not as a table: it sits next to the modules
 * themselves, which are also a list in a json column on the customer, and it
 * belongs to nothing but that customer.
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

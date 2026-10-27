<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * From when the current billing term runs.
 *
 * The periods were always counted from the start date. If a customer moved from
 * monthly to yearly halfway through the year, the yearly period ran from
 * January -- a period for which a monthly invoice had already been sent in
 * January, so the year counted as paid and the customer got the rest of it for
 * free. The term therefore gets a start date of its own, separate from the
 * start date on the screen.
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

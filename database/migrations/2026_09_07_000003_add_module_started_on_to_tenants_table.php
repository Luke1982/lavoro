<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When a module was switched on, per module.
 *
 * Without that date there is no working out how much of the current month a
 * customer had the module, and they pay a whole month for something they added
 * on the seventh.
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

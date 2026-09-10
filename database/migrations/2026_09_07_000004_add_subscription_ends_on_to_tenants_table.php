<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The last day of the subscription.
 *
 * Cancelling did not exist: a customer ran on until someone deleted them, and
 * then they were suddenly gone without the last month being settled.
 */
return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->date('subscription_ends_on')->nullable()->after('subscription_started_on');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', fn (Blueprint $t) => $t->dropColumn('subscription_ends_on'));
    }
};

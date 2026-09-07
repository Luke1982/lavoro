<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * De laatste dag van het abonnement.
 *
 * Opzeggen bestond niet: een klant liep door tot iemand hem weggooide, en dan
 * was hij ineens weg zonder dat de laatste maand verrekend was.
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

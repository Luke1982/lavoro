<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where an outstanding charge comes from, next to the amount.
 *
 * A settlement grows: switching a module on and then agreeing its price is two
 * changes that belong on one line. To keep being able to describe that line --
 * from which package to which, from which amount to which -- more has to be
 * kept than the sentence that is on it now.
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

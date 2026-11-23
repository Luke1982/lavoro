<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A version of a price formula can carry a name of its own, such as the year
 * its prices are for, so "versie 3" reads as "versie 3 (2027)".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('price_formula_versions', function (Blueprint $table) {
            $table->string('name', 60)->nullable()->after('number');
        });
    }

    public function down(): void
    {
        Schema::table('price_formula_versions', function (Blueprint $table) {
            $table->dropColumn('name');
        });
    }
};

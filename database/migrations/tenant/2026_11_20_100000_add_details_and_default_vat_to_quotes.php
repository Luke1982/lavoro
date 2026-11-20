<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A line gets a second, smaller line under its name: what a product is
 * ("wandmodel, inclusief standaard montage"), or the paragraph under a text
 * heading. A quote gets the VAT rate its new lines start with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('quote_lines', function (Blueprint $table) {
            $table->text('details')->nullable()->after('description');
        });

        Schema::table('quotes', function (Blueprint $table) {
            $table->decimal('default_vat_percent', 5, 2)->default(21)->after('customer_picks_options');
        });
    }

    public function down(): void
    {
        Schema::table('quotes', function (Blueprint $table) {
            $table->dropColumn('default_vat_percent');
        });

        Schema::table('quote_lines', function (Blueprint $table) {
            $table->dropColumn('details');
        });
    }
};

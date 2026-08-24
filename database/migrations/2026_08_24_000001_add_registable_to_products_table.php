<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a machine of this product carries a serial number of its own. Everything that is
 * not a bundle carried one until now, so that is where existing products land; a bundle
 * never did and never will.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->boolean('registable')->default(true)->after('bundle');
        });

        DB::table('products')->where('bundle', true)->update(['registable' => false]);
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('registable');
        });
    }
};

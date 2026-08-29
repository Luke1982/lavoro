<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A part whose number is not fixed by the product but filled in when the bundle is sold —
 * one omvormer with however many panelen this roof happens to take. The quantity column
 * stays as the number to start from; flex_quantity says it may be overruled.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('productables', function (Blueprint $table) {
            $table->boolean('flex_quantity')->default(false)->after('quantity');
        });
    }

    public function down(): void
    {
        Schema::table('productables', function (Blueprint $table) {
            $table->dropColumn('flex_quantity');
        });
    }
};

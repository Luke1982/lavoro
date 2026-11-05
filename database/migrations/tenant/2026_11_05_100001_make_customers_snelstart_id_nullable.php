<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The create migration made snelstart_id nullable in October 2025, after it had
 * already run on the first dedicated installations. Their column stayed NOT
 * NULL, and importing such an installation brings that along: nothing else
 * changes it, because the migration counts as done. A customer without a
 * SnelStart relation then cannot be written there by any path that leaves the
 * field empty. A no-op where the column is already nullable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('snelstart_id')->nullable()->change();
        });
    }

    public function down(): void {}
};

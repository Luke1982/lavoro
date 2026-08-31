<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('central')->table('pending_charges', function (Blueprint $table) {
            $table->string('kind')->default('other')->after('description');
        });

        Schema::connection('central')->table('invoice_lines', function (Blueprint $table) {
            $table->string('kind')->default('other')->after('description');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('pending_charges', function (Blueprint $table) {
            $table->dropColumn('kind');
        });

        Schema::connection('central')->table('invoice_lines', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};

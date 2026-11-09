<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Where someone lives, so a route can start and end at their home instead of
 * at the company.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('home_address')->nullable();
            $table->string('home_postal_code', 20)->nullable();
            $table->string('home_city')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['home_address', 'home_postal_code', 'home_city']);
        });
    }
};

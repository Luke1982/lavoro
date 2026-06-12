<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('userables', function (Blueprint $table) {
            $table->boolean('has_diverging_times')->default(false)->after('breaktime');
            $table->time('diverging_start')->nullable()->after('has_diverging_times');
            $table->time('diverging_end')->nullable()->after('diverging_start');
        });
    }

    public function down(): void
    {
        Schema::table('userables', function (Blueprint $table) {
            $table->dropColumn(['has_diverging_times', 'diverging_start', 'diverging_end']);
        });
    }
};

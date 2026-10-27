<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assetables', function (Blueprint $table) {
            $table->timestamp('last_generated_at')->nullable()->after('frequency_days');
        });
    }

    public function down(): void
    {
        Schema::table('assetables', function (Blueprint $table) {
            $table->dropColumn('last_generated_at');
        });
    }
};

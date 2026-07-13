<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('manage_frequency_per_asset');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->dropColumn('cancelled_at');
        });
    }
};

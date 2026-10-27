<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->boolean('auto_generate')->default(false)->after('frequency_days');
            $table->string('auto_generate_interval')->nullable()->after('auto_generate');
            $table->unsignedInteger('auto_generate_interval_days')->nullable()->after('auto_generate_interval');
        });
    }

    public function down(): void
    {
        Schema::table('maintenance_contracts', function (Blueprint $table) {
            $table->dropColumn(['auto_generate', 'auto_generate_interval', 'auto_generate_interval_days']);
        });
    }
};

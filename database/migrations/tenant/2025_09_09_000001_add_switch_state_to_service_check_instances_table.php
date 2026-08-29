<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (
            Schema::hasTable('service_check_instances') &&
            ! Schema::hasColumn('service_check_instances', 'switch_state')
        ) {
            Schema::table('service_check_instances', function (Blueprint $table) {
                $table->boolean('switch_state')->nullable()->after('updated_at');
            });
        }
    }

    public function down(): void
    {
        if (
            Schema::hasTable('service_check_instances') &&
            Schema::hasColumn('service_check_instances', 'switch_state')
        ) {
            Schema::table('service_check_instances', function (Blueprint $table) {
                $table->dropColumn('switch_state');
            });
        }
    }
};

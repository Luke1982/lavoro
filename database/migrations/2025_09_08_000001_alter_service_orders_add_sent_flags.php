<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (
                Schema::hasColumn('service_orders', 'sent') &&
                !Schema::hasColumn('service_orders', 'sent_to_administration')
            ) {
                $table->boolean('sent_to_administration')->default(false)->after('signature_base64');
            }
            if (!Schema::hasColumn('service_orders', 'sent_to_customer')) {
                $table->boolean('sent_to_customer')->default(false)->after('sent_to_administration');
            }
        });

        // Migrate existing data
        if (Schema::hasColumn('service_orders', 'sent')) {
            DB::statement('UPDATE service_orders SET sent_to_administration = sent WHERE sent = 1');
        }
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            if (Schema::hasColumn('service_orders', 'sent_to_customer')) {
                $table->dropColumn('sent_to_customer');
            }
            if (Schema::hasColumn('service_orders', 'sent_to_administration')) {
                $table->dropColumn('sent_to_administration');
            }
        });
    }
};

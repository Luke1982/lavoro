<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (!Schema::hasColumn('customers', 'lat')) {
                $table->decimal('lat', 10, 7)->nullable()->after('billing_customer_id');
            }
            if (!Schema::hasColumn('customers', 'lon')) {
                $table->decimal('lon', 10, 7)->nullable()->after('lat');
            }
            $table->index(['lat', 'lon']);
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            if (Schema::hasColumn('customers', 'lat')) {
                $table->dropColumn('lat');
            }
            if (Schema::hasColumn('customers', 'lon')) {
                $table->dropColumn('lon');
            }
        });
    }
};

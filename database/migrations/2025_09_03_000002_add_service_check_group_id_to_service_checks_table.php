<?php

use App\Models\ServiceCheckGroup;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_checks', function (Blueprint $table) {
            $table->foreignIdFor(ServiceCheckGroup::class)
                ->nullable()
                ->after('product_type_id')
                ->constrained()
                ->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('service_checks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('service_check_group_id');
        });
    }
};

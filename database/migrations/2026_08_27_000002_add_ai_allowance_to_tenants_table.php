<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'central';

    public function up(): void
    {
        Schema::connection('central')->table('tenants', function (Blueprint $table) {
            $table->unsignedBigInteger('ai_allowance_micros')->nullable()->after('storage_limit_gb');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', fn (Blueprint $t) => $t->dropColumn('ai_allowance_micros'));
    }
};

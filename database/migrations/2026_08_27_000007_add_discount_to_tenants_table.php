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
            $table->unsignedInteger('discount_cents')->nullable()->after('price_override_cents');
            $table->unsignedTinyInteger('discount_percent')->nullable()->after('discount_cents');
        });
    }

    public function down(): void
    {
        Schema::connection('central')->table('tenants', fn (Blueprint $t) => $t->dropColumn(['discount_cents', 'discount_percent']));
    }
};

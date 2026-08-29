<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('retail_price', 10, 2)->nullable()->after('typical_certificate_days');
            $table->decimal('purchase_price', 10, 2)->nullable()->after('retail_price');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['retail_price', 'purchase_price']);
        });
    }
};

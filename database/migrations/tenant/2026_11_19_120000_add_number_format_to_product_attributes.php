<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('product_attributes', function (Blueprint $table) {
            $table->string('value_type')->default('text')->after('name');
            $table->string('unit', 20)->nullable()->after('value_type');
        });

        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->decimal('number', 18, 6)->nullable()->after('value');
            $table->index(['product_attribute_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::table('product_attribute_values', function (Blueprint $table) {
            $table->dropIndex(['product_attribute_id', 'number']);
            $table->dropColumn('number');
        });

        Schema::table('product_attributes', function (Blueprint $table) {
            $table->dropColumn(['value_type', 'unit']);
        });
    }
};

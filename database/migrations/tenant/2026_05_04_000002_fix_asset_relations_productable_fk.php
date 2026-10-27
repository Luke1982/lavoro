<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('asset_relations', function (Blueprint $table) {
            $table->dropForeign(['productable_id']);
            $table->foreign('productable_id')
                ->references('id')
                ->on('productables')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('asset_relations', function (Blueprint $table) {
            $table->dropForeign(['productable_id']);
            $table->foreign('productable_id')
                ->references('id')
                ->on('productables');
        });
    }
};

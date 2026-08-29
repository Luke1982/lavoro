<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imageables', function (Blueprint $table) {
            $table->boolean('main')->default(false)->after('image_id');
        });
    }

    public function down(): void
    {
        Schema::table('imageables', function (Blueprint $table) {
            $table->dropColumn('main');
        });
    }
};

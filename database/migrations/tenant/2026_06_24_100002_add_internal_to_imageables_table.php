<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('imageables', function (Blueprint $table) {
            $table->boolean('internal')->default(false)->after('main');
        });
    }

    public function down(): void
    {
        Schema::table('imageables', function (Blueprint $table) {
            $table->dropColumn('internal');
        });
    }
};

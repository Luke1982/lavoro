<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_user_executions', function (Blueprint $table) {
            $table->unsignedSmallInteger('travel_time_minutes')->default(0)->after('actual_end');
        });
    }

    public function down(): void
    {
        Schema::table('event_user_executions', function (Blueprint $table) {
            $table->dropColumn('travel_time_minutes');
        });
    }
};

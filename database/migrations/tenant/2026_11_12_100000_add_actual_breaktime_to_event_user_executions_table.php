<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_user_executions', function (Blueprint $table) {
            $table->unsignedSmallInteger('actual_breaktime_minutes')->nullable()->after('travel_time_minutes');
        });
    }

    public function down(): void
    {
        Schema::table('event_user_executions', function (Blueprint $table) {
            $table->dropColumn('actual_breaktime_minutes');
        });
    }
};

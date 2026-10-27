<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('seat_type')->default('office')->after('email');
            $table->index('seat_type');
        });

        /** Whoever can be planned works in the field; the rest is office staff. */
        DB::table('users')->where('plannable', true)->update(['seat_type' => 'field']);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['seat_type']);
            $table->dropColumn('seat_type');
        });
    }
};

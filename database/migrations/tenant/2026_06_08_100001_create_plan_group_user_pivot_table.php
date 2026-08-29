<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_group_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_plan_group_id')->constrained('user_plan_groups')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_plan_group_id', 'user_id']);
            $table->timestamps();
        });

        // Migrate existing single-group assignments to the pivot
        DB::table('users')
            ->whereNotNull('user_plan_group_id')
            ->orderBy('id')
            ->each(function ($user) {
                DB::table('plan_group_user')->insertOrIgnore([
                    'user_plan_group_id' => $user->user_plan_group_id,
                    'user_id'            => $user->id,
                    'created_at'         => now(),
                    'updated_at'         => now(),
                ]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['user_plan_group_id']);
            $table->dropColumn('user_plan_group_id');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('user_plan_group_id')->nullable()->constrained('user_plan_groups')->nullOnDelete();
        });

        // Restore first group membership back to the column
        DB::table('plan_group_user')
            ->orderBy('user_plan_group_id')
            ->each(function ($row) {
                DB::table('users')
                    ->where('id', $row->user_id)
                    ->whereNull('user_plan_group_id')
                    ->update(['user_plan_group_id' => $row->user_plan_group_id]);
            });

        Schema::dropIfExists('plan_group_user');
    }
};

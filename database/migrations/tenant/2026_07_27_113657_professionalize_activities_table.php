<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('activities', function (Blueprint $table) {
            $table->string('event_key')->nullable()->index()->after('category');
            $table->nullableMorphs('subject');
            $table->string('actor_type')->default('user')->after('user_id');
            $table->string('actor_name')->nullable()->after('actor_type');
            $table->timestamp('occurred_at')->nullable()->after('metadata');
        });

        Schema::table('activities', function (Blueprint $table) {
            $table->index(['subject_type', 'subject_id', 'occurred_at'], 'activities_subject_time_index');
            $table->index(['event_key', 'occurred_at'], 'activities_event_time_index');
        });

        DB::table('activities')->update(['occurred_at' => DB::raw('created_at')]);

        Schema::create('activity_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('activity_id')->constrained()->cascadeOnDelete();
            $table->string('field');
            $table->string('label')->nullable();
            $table->text('old_value')->nullable();
            $table->text('new_value')->nullable();
            $table->text('old_label')->nullable();
            $table->text('new_label')->nullable();
            $table->index(['activity_id', 'field']);

            /**
             * Only the field is indexed. new_value is a text column and MySQL
             * refuses to index one without a key length, so a composite index
             * over it would pass on SQLite and fail on the real database.
             */
            $table->index('field');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_changes');

        Schema::table('activities', function (Blueprint $table) {
            $table->dropIndex('activities_subject_time_index');
            $table->dropMorphs('subject');
            $table->dropColumn(['event_key', 'actor_type', 'actor_name', 'occurred_at']);
        });
    }
};

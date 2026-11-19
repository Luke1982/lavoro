<?php

use App\Models\Contact;
use App\Models\Location;
use App\Models\Project;
use App\Models\ProjectMilestone;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A project gets a number people can quote, a place picked from the customer's
 * locations, a contact person and a budget; a milestone gets the werkbonnen that
 * complete it. Existing projects are numbered in the order they were made, and the
 * history already written about them is put on their own timeline, where nothing
 * could read it before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->string('number', 20)->nullable()->unique()->after('id');
            $table->foreignIdFor(Location::class)->nullable()->after('location')->constrained()->nullOnDelete();
            $table->foreignIdFor(Contact::class)->nullable()->after('project_manager_id')->constrained()->nullOnDelete();
            $table->decimal('budget_hours', 10, 2)->nullable()->after('end_date');
            $table->decimal('budget_amount', 12, 2)->nullable()->after('budget_hours');
        });

        Schema::create('project_number_counters', function (Blueprint $table) {
            $table->unsignedSmallInteger('year')->primary();
            $table->unsignedInteger('last_number');
        });

        Schema::table('project_milestones', function (Blueprint $table) {
            $table->timestamp('overdue_notified_at')->nullable()->after('assigned_user_id');
        });

        Schema::table('service_orders', function (Blueprint $table) {
            $table->foreignIdFor(ProjectMilestone::class)->nullable()->after('project_id')->constrained()->nullOnDelete();
        });

        $this->numberExistingProjects();
        $this->attachExistingProjectHistory();
    }

    public function down(): void
    {
        Schema::table('service_orders', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(ProjectMilestone::class);
        });

        Schema::table('project_milestones', function (Blueprint $table) {
            $table->dropColumn('overdue_notified_at');
        });

        Schema::dropIfExists('project_number_counters');

        Schema::table('projects', function (Blueprint $table) {
            $table->dropConstrainedForeignIdFor(Contact::class);
            $table->dropConstrainedForeignIdFor(Location::class);
            $table->dropUnique(['number']);
            $table->dropColumn(['number', 'budget_hours', 'budget_amount']);
        });
    }

    private function numberExistingProjects(): void
    {
        $last_per_year = [];

        DB::table('projects')->orderBy('created_at')->orderBy('id')->get(['id', 'created_at'])
            ->each(function ($project) use (&$last_per_year) {
                $year = (int) substr((string) ($project->created_at ?? now()), 0, 4);
                $last_per_year[$year] = ($last_per_year[$year] ?? 0) + 1;

                DB::table('projects')->where('id', $project->id)->update([
                    'number' => 'P-' . $year . '-' . str_pad((string) $last_per_year[$year], 3, '0', STR_PAD_LEFT),
                ]);
            });

        foreach ($last_per_year as $year => $last_number) {
            DB::table('project_number_counters')->insert(['year' => $year, 'last_number' => $last_number]);
        }
    }

    private function attachExistingProjectHistory(): void
    {
        DB::table('activities')
            ->where('subject_type', Project::class)
            ->whereNotExists(fn ($query) => $query->select(DB::raw(1))
                ->from('activityables')
                ->whereColumn('activityables.activity_id', 'activities.id')
                ->where('activityables.activityable_type', Project::class))
            ->orderBy('id')
            ->get(['id', 'subject_id', 'occurred_at'])
            ->each(fn ($activity) => DB::table('activityables')->insert([
                'activity_id' => $activity->id,
                'activityable_type' => Project::class,
                'activityable_id' => $activity->subject_id,
                'created_at' => $activity->occurred_at,
                'updated_at' => $activity->occurred_at,
            ]));
    }
};

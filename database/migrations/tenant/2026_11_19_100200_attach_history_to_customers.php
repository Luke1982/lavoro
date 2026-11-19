<?php

use App\Models\Customer;
use App\Models\Project;
use App\Models\ServiceOrder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * History about a customer was written, but never attached to the customer: the
 * model had no timeline relation, so the trail skipped it without a word. This
 * puts the existing entries where the trail would have put them: the changes to
 * the customer itself, and the facts whose signals name the customer as a
 * timeline they also belong on.
 */
return new class extends Migration
{
    /**
     * The keys of TaskCheckChanged, ServiceOrderMacroRan and ProjectStatusChanged,
     * the signals that list the customer in activityContext(), by the subject they
     * are about and the table that subject's customer is read from.
     */
    private const ALSO_ON_THE_CUSTOMER = [
        ServiceOrder::class => ['service_orders', ['taskinstance.check_changed', 'serviceorder.macro_ran']],
        Project::class => ['projects', ['project.status_changed']],
    ];

    public function up(): void
    {
        $this->attach(DB::table('activities')
            ->where('activities.subject_type', Customer::class)
            ->select('activities.id', 'activities.subject_id as customer_id', 'activities.occurred_at'));

        foreach (self::ALSO_ON_THE_CUSTOMER as $subject_type => [$table, $event_keys]) {
            $this->attach(DB::table('activities')
                ->join($table, $table . '.id', '=', 'activities.subject_id')
                ->where('activities.subject_type', $subject_type)
                ->whereIn('activities.event_key', $event_keys)
                ->whereNotNull($table . '.customer_id')
                ->select('activities.id', $table . '.customer_id', 'activities.occurred_at'));
        }
    }

    public function down(): void
    {
        DB::table('activityables')->where('activityable_type', Customer::class)->delete();
    }

    private function attach(Builder $activities): void
    {
        $activities
            ->whereNotExists(fn (Builder $pivot) => $pivot->select(DB::raw(1))
                ->from('activityables')
                ->whereColumn('activityables.activity_id', 'activities.id')
                ->where('activityables.activityable_type', Customer::class))
            ->chunkById(500, fn ($rows) => DB::table('activityables')->insert($rows->map(fn ($row) => [
                'activity_id' => $row->id,
                'activityable_type' => Customer::class,
                'activityable_id' => $row->customer_id,
                'created_at' => $row->occurred_at,
                'updated_at' => $row->occurred_at,
            ])->all()), 'activities.id', 'id');
    }
};

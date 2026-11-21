<?php

use App\Models\Asset;
use App\Models\Customer;
use App\Models\IntegrationConnection;
use App\Models\LedgerAccount;
use App\Models\ServiceCheckInstance;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\ServiceOrderTaskInstance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * History about machines, inspections, tasks, ledger accounts and bookkeeping
 * connections was written, but attached to nothing: those models had no timeline
 * relation, and the trail skips a record without one in silence. No screen showed
 * those entries and the assistant could not find them.
 *
 * This attaches the existing entries where the trail now puts new ones: a machine's
 * on the machine and its customer, a task's and an inspection's on the work order
 * (and an inspection's on its machine), and a remark or photo on a keurpunt on its
 * work order and machine. Entries that land on another record's
 * timeline get the name of the record they are about, as new ones carry it, or
 * "Aangemaakt" there would not say what was made.
 *
 * Entries that only report fields a named signal already tells (completing,
 * signing and cancelling a task; mailing an inspection) are left where they were,
 * or the work order would show each of those twice.
 */
return new class extends Migration
{
    private const TOLD_ELSEWHERE = [
        ServiceOrderTaskInstance::class => [
            'is_complete', 'completed_at', 'completed_by', 'signed_by', 'signed_at',
            'is_cancelled', 'cancellation_reason', 'signature_base64',
        ],
        ServiceJob::class => ['sent_to_customer'],
    ];

    public function up(): void
    {
        foreach ([Asset::class => 'assets', LedgerAccount::class => 'ledger_accounts', IntegrationConnection::class => 'integration_connections'] as $type => $table) {
            $this->attach($type, DB::table('activities')
                ->join($table, $table . '.id', '=', 'activities.subject_id')
                ->where('activities.subject_type', $type)
                ->select('activities.id', 'activities.subject_id as target_id', 'activities.occurred_at'));
        }

        $this->attach(ServiceOrder::class, $this->worthTelling(ServiceOrderTaskInstance::class, DB::table('activities')
            ->join('service_order_task_instances as task', 'task.id', '=', 'activities.subject_id')
            ->where('activities.subject_type', ServiceOrderTaskInstance::class)
            ->select('activities.id', 'task.service_order_id as target_id', 'activities.occurred_at')));

        $this->attach(ServiceOrder::class, $this->worthTelling(ServiceJob::class, DB::table('activities')
            ->join('service_jobs as job', 'job.id', '=', 'activities.subject_id')
            ->where('activities.subject_type', ServiceJob::class)
            ->where('activities.event_key', '!=', 'servicejob.created')
            ->select('activities.id', 'job.service_order_id as target_id', 'activities.occurred_at')));

        $this->attach(Asset::class, $this->worthTelling(ServiceJob::class, DB::table('activities')
            ->join('service_jobs as job', 'job.id', '=', 'activities.subject_id')
            ->join('assets', 'assets.id', '=', 'job.asset_id')
            ->where('activities.subject_type', ServiceJob::class)
            ->select('activities.id', 'job.asset_id as target_id', 'activities.occurred_at')));

        $this->attach(Customer::class, DB::table('activities')
            ->join('assets', 'assets.id', '=', 'activities.subject_id')
            ->leftJoin('assets as parent', 'parent.id', '=', 'assets.parent_asset_id')
            ->leftJoin('assets as grandparent', 'grandparent.id', '=', 'parent.parent_asset_id')
            ->where('activities.subject_type', Asset::class)
            ->where('activities.event_key', 'like', 'asset.%')
            ->select(
                'activities.id',
                DB::raw('coalesce(assets.customer_id, parent.customer_id, grandparent.customer_id) as target_id'),
                'activities.occurred_at',
            ));

        foreach ([ServiceOrder::class => 'job.service_order_id', Asset::class => 'job.asset_id'] as $target_type => $target) {
            $this->attach($target_type, DB::table('activities')
                ->join('service_check_instances as checkpoint', 'checkpoint.id', '=', 'activities.subject_id')
                ->join('service_jobs as job', 'job.id', '=', 'checkpoint.service_job_id')
                ->where('activities.subject_type', ServiceCheckInstance::class)
                ->select('activities.id', $target . ' as target_id', 'activities.occurred_at'));
        }

        $this->nameTasks();
        $this->nameInspections();
        $this->nameMachines();
        $this->nameCheckpoints();
    }

    public function down(): void
    {
        foreach ([Asset::class, LedgerAccount::class, IntegrationConnection::class] as $type) {
            DB::table('activityables')->where('activityable_type', $type)->delete();
        }

        DB::table('activityables')
            ->where('activityable_type', Customer::class)
            ->whereIn('activity_id', DB::table('activities')->where('subject_type', Asset::class)->select('id'))
            ->delete();

        DB::table('activityables')
            ->where('activityable_type', ServiceOrder::class)
            ->whereIn('activity_id', DB::table('activities')
                ->whereIn('subject_type', [ServiceOrderTaskInstance::class, ServiceJob::class, ServiceCheckInstance::class])
                ->select('id'))
            ->delete();
    }

    /** Leaves out entries whose every change is one a named signal already reports. */
    private function worthTelling(string $type, Builder $activities): Builder
    {
        return $activities->where(fn (Builder $q) => $q
            ->where('activities.event_key', 'not like', '%.updated')
            ->orWhereExists(fn (Builder $changes) => $changes->select(DB::raw(1))
                ->from('activity_changes')
                ->whereColumn('activity_changes.activity_id', 'activities.id')
                ->whereNotIn('activity_changes.field', self::TOLD_ELSEWHERE[$type])));
    }

    private function nameTasks(): void
    {
        DB::table('activities')
            ->join('service_order_task_instances as task', 'task.id', '=', 'activities.subject_id')
            ->leftJoin('service_order_tasks as template', 'template.id', '=', 'task.service_order_task_id')
            ->where('activities.subject_type', ServiceOrderTaskInstance::class)
            ->whereNull('activities.metadata')
            ->select('activities.id', 'task.title', 'template.title as template_title', 'task.description')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $title = $row->title ?: ($row->template_title ?: Str::limit((string) $row->description, 60));

                    $this->label($row->id, $title === '' ? 'Taak' : 'Taak "' . $title . '"');
                }
            }, 'activities.id', 'id');
    }

    private function nameInspections(): void
    {
        DB::table('activities')
            ->join('service_jobs as job', 'job.id', '=', 'activities.subject_id')
            ->leftJoin('assets', 'assets.id', '=', 'job.asset_id')
            ->leftJoin('products', 'products.id', '=', 'assets.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->where('activities.subject_type', ServiceJob::class)
            ->whereNull('activities.metadata')
            ->select('activities.id', 'job.service_order_id', 'assets.serial_number', 'products.model', 'brands.name as brand')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $machine = $row->serial_number ?: trim($row->brand . ' ' . $row->model);
                    $order = $row->service_order_id === null ? '' : ' op WB-' . str_pad((string) $row->service_order_id, 4, '0', STR_PAD_LEFT);

                    $this->label($row->id, 'Keuring' . $order . ($machine === '' ? '' : ' (machine ' . $machine . ')'));
                }
            }, 'activities.id', 'id');
    }

    private function nameMachines(): void
    {
        DB::table('activities')
            ->join('assets', 'assets.id', '=', 'activities.subject_id')
            ->leftJoin('products', 'products.id', '=', 'assets.product_id')
            ->leftJoin('brands', 'brands.id', '=', 'products.brand_id')
            ->where('activities.subject_type', Asset::class)
            ->where('activities.event_key', 'like', 'asset.%')
            ->whereNull('activities.metadata')
            ->select('activities.id', 'assets.id as asset_id', 'assets.serial_number', 'products.model', 'brands.name as brand')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $name = trim($row->brand . ' ' . $row->model) ?: 'Machine #' . $row->asset_id;

                    $this->label($row->id, $row->serial_number ? $name . ' (serienummer ' . $row->serial_number . ')' : $name);
                }
            }, 'activities.id', 'id');
    }

    /** A remark or photo on a keurpunt already carries metadata of its own; the name is added to it. */
    private function nameCheckpoints(): void
    {
        DB::table('activities')
            ->join('service_check_instances as checkpoint', 'checkpoint.id', '=', 'activities.subject_id')
            ->join('service_checks as service_check', 'service_check.id', '=', 'checkpoint.service_check_id')
            ->where('activities.subject_type', ServiceCheckInstance::class)
            ->select('activities.id', 'activities.metadata', 'service_check.name')
            ->chunkById(500, function ($rows) {
                foreach ($rows as $row) {
                    $metadata = json_decode((string) $row->metadata, true) ?: [];

                    if (!isset($metadata['subject_label'])) {
                        $this->label($row->id, 'Keurpunt "' . $row->name . '"', $metadata);
                    }
                }
            }, 'activities.id', 'id');
    }

    /** @param array<string, mixed> $metadata */
    private function label(int $activity_id, string $label, array $metadata = []): void
    {
        DB::table('activities')
            ->where('id', $activity_id)
            ->update(['metadata' => json_encode([...$metadata, 'subject_label' => $label], JSON_UNESCAPED_UNICODE)]);
    }

    private function attach(string $target_type, Builder $activities): void
    {
        $activities
            ->whereNotNull('activities.subject_id')
            ->whereNotExists(fn (Builder $pivot) => $pivot->select(DB::raw(1))
                ->from('activityables')
                ->whereColumn('activityables.activity_id', 'activities.id')
                ->where('activityables.activityable_type', $target_type))
            ->chunkById(500, fn ($rows) => DB::table('activityables')->insert($rows
                ->filter(fn ($row) => $row->target_id !== null)
                ->map(fn ($row) => [
                    'activity_id' => $row->id,
                    'activityable_type' => $target_type,
                    'activityable_id' => $row->target_id,
                    'created_at' => $row->occurred_at,
                    'updated_at' => $row->occurred_at,
                ])->values()->all()), 'activities.id', 'id');
    }
};

<?php

namespace App\Models;

use App\Enums\ServiceJobOutcomes;
use App\Models\Traits\HasExecutingUsers;
use App\Models\Traits\HasOwner;
use App\Models\Traits\RecordsHistory;
use Database\Factories\ServiceJobFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceJob extends Model
{
    use HasExecutingUsers;

    /** @use HasFactory<ServiceJobFactory> */
    use HasFactory;

    use HasOwner;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    use RecordsHistory;

    protected array $activity_labels = [
        'service_order_id' => 'Werkbon',
        'completed_by' => 'Afgerond door',
        'parent_service_job_id' => 'Bovenliggende keuring',
        'asset_id' => 'Machine',
        'outcome' => 'Uitkomst',
        'description' => 'Omschrijving',
        'completed_on' => 'Afgerond op',
        'sent_to_customer' => 'Verzonden naar klant',
        'days_temporary_approval' => 'Dagen tijdelijke goedkeuring',
    ];

    protected array $activity_relations = [
        'asset_id' => ['asset', 'serial_number'],
    ];

    /**
     * Mailing an inspection is told by ServiceJobEmailed, with the recipients;
     * finishing and reopening it by ServiceJobCompleted and ServiceJobReopened, which
     * carry the outcome, the days of a temporary approval, the date and who did it,
     * before and after. None of them changes on any other path.
     */
    protected array $activity_ignore = [
        'sent_to_customer',
        'outcome',
        'days_temporary_approval',
        'completed_on',
        'completed_by',
    ];

    protected $fillable = [
        'asset_id',
        'service_order_id',
        'outcome',
        'days_temporary_approval',
        'description',
        'completed_on',
        'completed_by',
        'parent_service_job_id',
        'sent_to_customer',
    ];

    protected $casts = [
        'completed_on' => 'date',
        'sent_to_customer' => 'boolean',
    ];

    /**
     * An inspection has no screen of its own: it is read on the work order it was
     * done for and on the machine it was done on. Its creation is already told on
     * the work order by ServiceJobAdded, so only the machine hears about that.
     */
    public function historyTimelines(string $action): array
    {
        return $action === 'created'
            ? [$this->asset]
            : [$this->serviceOrder, $this->asset];
    }

    /**
     * "Keuring op WB-0042 (machine 12345)": on the machine's timeline the werkbon is
     * what tells one keuring from the next. The machine goes by its serial, or by its
     * product while it has none.
     */
    public function historyLabel(): ?string
    {
        $number = $this->serviceOrder?->number;
        $machine = $this->asset?->serial_number ?: $this->asset?->product?->display_name;

        return 'Keuring' . ($number ? ' op ' . $number : '') . ($machine ? ' (machine ' . $machine . ')' : '');
    }

    protected static function booted()
    {
        static::created(function (ServiceJob $job) {
            $checks = $job->asset?->product?->productType?->serviceChecks()->get() ?? collect();

            $checks->each(fn ($check) => $job->checkInstances()->create([
                'service_check_id' => $check->id,
            ]));
        });
    }

    public function parentJob()
    {
        return $this->belongsTo(ServiceJob::class, 'parent_service_job_id');
    }

    public function childJobs()
    {
        return $this->hasMany(ServiceJob::class, 'parent_service_job_id');
    }

    /**
     * The asset associated with the service job.
     */
    public function asset()
    {
        return $this->belongsTo(Asset::class);
    }

    public function checkInstances()
    {
        return $this
            ->hasMany(ServiceCheckInstance::class)
            ->join('service_checks', 'service_check_instances.service_check_id', '=', 'service_checks.id')
            ->leftJoin('service_check_groups', 'service_checks.service_check_group_id', '=', 'service_check_groups.id')
            // order by group order first (nulls last), then by check order
            ->orderByRaw(
                'CASE WHEN service_check_groups.`order` IS NULL THEN 1 ELSE 0 END, '
                . 'service_check_groups.`order` ASC'
            )
            ->orderBy('service_checks.order')
            ->select('service_check_instances.*');
    }

    /**
     * The service order associated with the service job.
     */
    public function serviceOrder()
    {
        return $this->belongsTo(ServiceOrder::class);
    }

    /**
     * The keuringen this person may read: with the right to read keuringen, on a
     * werkbon they may see. A keuring without a werkbon is nobody's to read here.
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        if (!$user?->can('viewAny', self::class)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn($query->qualifyColumn('service_order_id'), ServiceOrder::visibleTo($user)->select('service_orders.id'));
    }

    /**
     * Get the number of days to advance the next service date,
     * based on the outcome of the service job.
     *
     * @param  int|null  $tmp_days  The number of days for temporary approval, if applicable.
     */
    public function getDaysToAdvanceNextServiceDate($tmp_days): ?int
    {
        return $this->daysToAdvanceFor($this->outcome, $tmp_days);
    }

    /**
     * The days an outcome moves the machine's next service date: its certificate
     * period for an approval, the days given for a temporary one, none otherwise.
     * Also asked for the outcome a keuring had before it was saved again.
     *
     * @param  int|null  $tmp_days  The number of days for temporary approval, if applicable.
     */
    public function daysToAdvanceFor(?string $outcome, $tmp_days): ?int
    {
        if ($outcome === ServiceJobOutcomes::goedkeur->value) {
            $this->loadMissing('asset.product.productType');

            return $this->asset->product->effectiveCertificateDays();
        }

        if ($outcome === ServiceJobOutcomes::tijdelijk_goedkeur->value) {
            return $tmp_days;
        }

        return null;
    }

    /**
     * The user who completed the service job.
     */
    public function completedBy()
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    /**
     * Check instances that are not yet filled in for this job.
     */
    public function incompleteCheckInstances()
    {
        $this->loadMissing('checkInstances.serviceCheck', 'checkInstances.values');

        return $this->checkInstances->filter(function ($ci) {
            $type = $ci->serviceCheck?->type;
            if ($type === 'boolean') {
                return $ci->switch_state === null;
            }
            if ($type === 'text' || $type === 'number') {
                return trim((string) ($ci->description ?? '')) === '';
            }
            if ($type === 'radio') {
                return $ci->values->count() !== 1;
            }
            if ($type === 'checkgroup') {
                return $ci->values->count() === 0;
            }

            return false;
        });
    }
}

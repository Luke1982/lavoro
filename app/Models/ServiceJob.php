<?php

namespace App\Models;

use App\Enums\ServiceJobOutcomes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class ServiceJob extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceJobFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'asset_id',
        'service_order_id',
        'outcome',
        'days_temporary_approval',
        'description',
        'completed_on',
    ];

    protected static function booted()
    {
        static::created(function (ServiceJob $job) {
            $checks = $job->asset
                          ->product
                          ->productType
                          ->checks;

            $checks->each(fn($check) => $job->checkInstances()->create([
                'service_check_id' => $check->id,
            ]));
        });
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
     * Get the number of days to advance the next service date,
     * based on the outcome of the service job.
     *
     * @param int|null $tmp_days The number of days for temporary approval, if applicable.
     */
    public function getDaysToAdvanceNextServiceDate($tmp_days): ?int
    {
        $days = null;

        if ($this->outcome === ServiceJobOutcomes::goedkeur->value) {
            $this->load('asset.product.productType');
            $days = $this->asset->product->typical_certificate_days ??
                $this->asset->product->productType->typical_certificate_days;
        } elseif ($this->outcome === ServiceJobOutcomes::tijdelijk_goedkeur->value) {
            $days = $tmp_days;
        }

        return $days;
    }
}

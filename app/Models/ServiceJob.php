<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

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
        return $this->hasMany(ServiceCheckInstance::class)->orderBy('order');
    }
}

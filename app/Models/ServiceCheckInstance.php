<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Traits\RemarkableTrait;

class ServiceCheckInstance extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceCheckInstanceFactory> */
    use HasFactory;
    use RemarkableTrait;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'service_check_id',
        'service_job_id',
        'service_check_value_id',
        'description',
        'switch_state',
    ];

    protected $casts = [
        'switch_state' => 'boolean',
    ];

    /**
     * The service check associated with the instance.
     */
    public function serviceCheck()
    {
        return $this->belongsTo(ServiceCheck::class);
    }

    /**
     * The service job associated with the instance.
     */
    public function serviceJob()
    {
        return $this->belongsTo(ServiceJob::class);
    }

    /**
     * The service check value associated with the instance.
     */
    public function values()
    {
        return $this->belongsToMany(ServiceCheckValue::class, 'check_instance_service_value')
            ->withTimestamps();
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceCheckInstance extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceCheckInstanceFactory> */
    use HasFactory;

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
    ];
}

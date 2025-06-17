<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceCheckValue extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceCheckValueFactory> */
    use HasFactory;

    protected $fillable = [
        'service_check_id',
        'order',
        'value',
    ];
}

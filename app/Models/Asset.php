<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Asset extends Model
{
    /** @use HasFactory<\Database\Factories\AssetsFactory> */
    use HasFactory;

    protected $fillable = [
        'product_id',
        'customer_id',
        'serial_number',
        'next_service_date',
        'status',
    ];
}

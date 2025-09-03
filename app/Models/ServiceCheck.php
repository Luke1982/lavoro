<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ServiceCheck extends Model
{
    /** @use HasFactory<\Database\Factories\ServiceCheckFactory> */
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'name',
        'type',
        'order',
        'product_type_id',
        'service_check_group_id',
    ];

    public function values()
    {
        return $this->hasMany(ServiceCheckValue::class)->orderBy('order');
    }

    public function productType()
    {
        return $this->belongsTo(ProductType::class);
    }

    public function group()
    {
        return $this->belongsTo(ServiceCheckGroup::class, 'service_check_group_id');
    }
}

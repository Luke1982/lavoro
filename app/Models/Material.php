<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Material extends Model
{
    protected $fillable = [
        'name',
        'description',
        'category_id',
        'usage_unit_id',
        'price',
        'snelstart_id',
        'code',
        'vendor_code',
        'cost_price',
        'divisable',
        'is_active',
        'is_service',
        'stock',
        'min_stock',
        'max_stock',
    ];

    public function category()
    {
        return $this->belongsTo(MaterialCategory::class);
    }

    public function usageUnit()
    {
        return $this->belongsTo(MaterialUsageUnit::class);
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable');
    }
}

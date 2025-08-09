<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property bool $divisable
 * @property bool $is_active
 * @property bool $is_service
 */
class Material extends Model
{
    protected $fillable = [
        'name',
        'description',
        'material_category_id',
        'material_usage_unit_id',
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

    protected $casts = [
        'divisable'  => 'boolean',
        'is_active'  => 'boolean',
        'is_service' => 'boolean',
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

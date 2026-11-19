<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

class ProductAttributeValue extends Model
{
    use HasFactory;

    protected $fillable = ['product_attribute_id', 'value', 'number'];

    protected $casts = [
        'number' => 'float',
    ];

    public function productAttribute(): BelongsTo
    {
        return $this->belongsTo(ProductAttribute::class);
    }

    public function products(): MorphToMany
    {
        return $this->morphedByMany(Product::class, 'productattributevalueable', 'productattributevalueables');
    }
}

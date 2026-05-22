<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductAttributeValueable extends Model
{
    protected $table = 'productattributevalueables';

    protected $fillable = [
        'product_attribute_value_id',
        'productattributevalueable_type',
        'productattributevalueable_id',
        'product_attribute_id',
    ];

    public function value()
    {
        return $this->belongsTo(ProductAttributeValue::class, 'product_attribute_value_id');
    }

    public function productAttribute()
    {
        return $this->belongsTo(ProductAttribute::class);
    }
}

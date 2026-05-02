<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

class Productable extends MorphPivot
{
    protected $table = 'productables';

    protected $fillable = [
        'product_id',
        'productable_type',
        'productable_id',
        'product_relation_id',
        'quantity',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'quantity'    => 'integer',
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function productRelation()
    {
        return $this->belongsTo(ProductRelation::class);
    }

    public function childProduct()
    {
        return $this->belongsTo(Product::class, 'productable_id');
    }
}

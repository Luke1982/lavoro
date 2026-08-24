<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\MorphPivot;

class Productable extends MorphPivot
{
    protected $table = 'productables';

    /**
     * The table has an id of its own, unlike the anonymous pivots MorphPivot assumes, and
     * rows are addressed by it everywhere — a part slot on a machine points at one.
     */
    public $incrementing = true;

    protected $fillable = [
        'product_id',
        'productable_type',
        'productable_id',
        'product_relation_id',
        'quantity',
        'flex_quantity',
        'is_required',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'flex_quantity' => 'boolean',
        'quantity' => 'integer',
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

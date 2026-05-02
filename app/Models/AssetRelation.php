<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AssetRelation extends Model
{
    protected $fillable = [
        'parent_asset_id',
        'child_asset_id',
        'productable_id',
        'product_relation_id',
    ];

    public function parentAsset()
    {
        return $this->belongsTo(Asset::class, 'parent_asset_id');
    }

    public function childAsset()
    {
        return $this->belongsTo(Asset::class, 'child_asset_id');
    }

    public function productable()
    {
        return $this->belongsTo(Productable::class);
    }

    public function productRelation()
    {
        return $this->belongsTo(ProductRelation::class);
    }
}

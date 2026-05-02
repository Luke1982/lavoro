<?php

namespace App\Models;

use App\Models\Traits\HasCustomFields;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    /** @use HasFactory<\Database\Factories\ProductFactory> */
    use HasFactory;
    use HasCustomFields;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<string>
     */
    protected $fillable = [
        'product_type_id',
        'brand_id',
        'model',
        'description',
        'start_sell',
        'end_sell',
        'typical_certificate_days',
        'retail_price',
        'purchase_price',
    ];

    protected $casts = [
        'retail_price'   => 'decimal:2',
        'purchase_price' => 'decimal:2',
    ];

    /**
     * The product type associated with the product.
     */
    public function productType()
    {
        return $this->belongsTo(ProductType::class);
    }

    /**
     * The brand associated with the product.
     */
    public function brand()
    {
        return $this->belongsTo(Brand::class);
    }

    public function images()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withTimestamps();
    }

    public function documents()
    {
        return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }
}

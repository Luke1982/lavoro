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

    protected static function booted(): void
    {
        static::deleting(function (Product $product): void {
            Productable::where('productable_type', Product::class)
                ->where('productable_id', $product->id)
                ->delete();
        });
    }

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
        'part_no',
        'bundle',
        'active',
        'warranty',
    ];

    protected $casts = [
        'retail_price'   => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'bundle'         => 'boolean',
        'active'         => 'boolean',
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
            ->withPivot(['main'])
            ->withTimestamps();
    }

    public function mainImage()
    {
        return $this->morphToMany(Image::class, 'imageable')
            ->withPivot(['main'])
            ->wherePivot('main', true)
            ->limit(1);
    }

    public function documents()
    {
        return $this->morphToMany(Document::class, 'documentable')->withTimestamps();
    }

    public function assets()
    {
        return $this->hasMany(Asset::class);
    }

    public function childProducts()
    {
        return $this->morphedByMany(Product::class, 'productable')
            ->withPivot(['id', 'product_relation_id', 'quantity', 'is_required'])
            ->using(Productable::class)
            ->withTimestamps();
    }

    public function parentProducts()
    {
        return $this->morphToMany(Product::class, 'productable')
            ->withPivot(['id', 'product_relation_id', 'quantity', 'is_required'])
            ->using(Productable::class)
            ->withTimestamps();
    }

    public function productables()
    {
        return $this->hasMany(Productable::class);
    }

    public function suppliers()
    {
        return $this->morphToMany(Supplier::class, 'suppliable')
            ->withPivot('article_number', 'is_preferred')
            ->withTimestamps();
    }

    public function productAttributeValueables()
    {
        return $this->morphMany(ProductAttributeValueable::class, 'productattributevalueable');
    }

    public function effectiveCertificateDays(int $fallback = 365): int
    {
        return $this->typical_certificate_days
            ?? $this->productType?->typical_certificate_days
            ?? $fallback;
    }

    public function attributeValueMap(): array
    {
        return $this->productAttributeValueables
            ->mapWithKeys(fn($pvable) => [
                $pvable->productAttribute->name => $pvable->value?->value,
            ])
            ->filter(fn($v) => $v !== null)
            ->all();
    }
}

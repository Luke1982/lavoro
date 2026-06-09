<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProductAttribute extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'searchable'];
    protected $casts = [
        'searchable' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::deleting(function (ProductAttribute $attribute): void {
            $attribute->productTypes()->detach();
        });
    }

    public function values()
    {
        return $this->hasMany(ProductAttributeValue::class);
    }

    public function productTypes()
    {
        return $this->morphToMany(ProductType::class, 'producttypeable');
    }
}

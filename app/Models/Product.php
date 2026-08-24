<?php

namespace App\Models;

use App\Models\Traits\HasCustomFields;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

class Product extends Model
{
    use HasCustomFields;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::deleting(function (Product $product): void {
            Productable::where('productable_type', Product::class)
                ->where('productable_id', $product->id)
                ->delete();
        });
    }

    /**
     * Limits a query to the products this person may see, mirroring ProductPolicy:
     * everything with product.read, otherwise only the products on their own open
     * werkbonnen, and nothing at all without either.
     */
    public function scopeVisibleTo($query, ?User $user)
    {
        if (!$user) {
            return $query->whereRaw('1 = 0');
        }

        if ($user->isAdmin() || $user->hasPermission('product.read')) {
            return $query;
        }

        if ($user->hasPermission('product.read.relevant.serviceorder')) {
            return $query->whereIn('id', $user->relevantProductIds());
        }

        return $query->whereRaw('1 = 0');
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
        'registable',
        'active',
        'warranty',
    ];

    protected $casts = [
        'retail_price' => 'decimal:2',
        'purchase_price' => 'decimal:2',
        'bundle' => 'boolean',
        'registable' => 'boolean',
        'active' => 'boolean',
    ];

    protected $appends = ['specific_attributes'];

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

    /**
     * How a product is named to a person: the brand followed by the model, which
     * is what every screen showing a product already spells out by hand. There is
     * no name column, so anything reading one gets nothing.
     *
     * Deliberately not appended: it costs a brand lookup, and most payloads
     * carrying a product do not show its name.
     */
    public function getDisplayNameAttribute(): ?string
    {
        $label = trim(($this->brand?->name ?? '') . ' ' . ($this->model ?? ''));

        return $label === '' ? null : $label;
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
            ->withPivot(['id', 'product_relation_id', 'quantity', 'flex_quantity', 'is_required'])
            ->using(Productable::class)
            ->withTimestamps();
    }

    public function parentProducts()
    {
        return $this->morphToMany(Product::class, 'productable')
            ->withPivot(['id', 'product_relation_id', 'quantity', 'flex_quantity', 'is_required'])
            ->using(Productable::class)
            ->withTimestamps();
    }

    /**
     * The catalogue rows describing what this product is built out of. Scoped to the rows
     * that point at another product: a werkbon taak records its own chosen aantallen in the
     * same table under this product's id, and those are not part of the catalogue.
     */
    public function productables()
    {
        return $this->hasMany(Productable::class)
            ->where('productable_type', Product::class);
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

    public function getSpecificAttributesAttribute(): array
    {
        if (!$this->relationLoaded('productAttributeValueables')) {
            return [];
        }

        return $this->productAttributeValueables
            ->map(fn ($pvable) => [
                'name' => $pvable->productAttribute?->name,
                'value' => $pvable->value?->value,
            ])
            ->filter(fn ($item) => $item['name'] !== null && $item['value'] !== null)
            ->values()
            ->all();
    }

    /**
     * Whether a machine of this product carries a serial number of its own. A bundle is a
     * container and has none, and a product that is not registable is handled by the piece
     * rather than by the item, so there is nothing to write down for either.
     */
    public function requiresSerial(): bool
    {
        return !$this->bundle && $this->registable;
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
            ->mapWithKeys(fn ($pvable) => [
                $pvable->productAttribute->name => $pvable->value?->value,
            ])
            ->filter(fn ($v) => $v !== null)
            ->all();
    }

    public function scopeWithAttributeData($query)
    {
        return $query->with([
            'brand',
            'productType',
            'productAttributeValueables.productAttribute',
            'productAttributeValueables.value',
        ]);
    }

    public function searchableAttributes(): Collection
    {
        return $this->productAttributeValueables
            ->filter(fn ($pvable) => $pvable->productAttribute?->searchable && $pvable->value)
            ->map(fn ($pvable) => [
                'name' => $pvable->productAttribute->name,
                'value' => $pvable->value->value,
            ])
            ->values();
    }

    public function toComboOption(): array
    {
        $attributes = $this->searchableAttributes();

        return [
            'id' => $this->id,
            'name' => "{$this->brand->name} {$this->model} ({$this->productType->name})",
            'bundle' => $this->bundle,
            'registable' => $this->registable,
            'attributes' => $attributes,
            'search' => $attributes->map(fn ($a) => "{$a['name']} {$a['value']}")->implode(' '),
        ];
    }
}

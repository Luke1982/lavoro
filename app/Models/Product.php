<?php

namespace App\Models;

use App\Actions\Products\ProductComponentAction;
use App\Actions\Suppliers\SupplierLinkAction;
use App\Models\Traits\HasActivities;
use App\Models\Traits\HasCustomFields;
use App\Models\Traits\RecordsHistory;
use App\Support\Money;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

class Product extends Model
{
    use HasActivities;
    use HasCustomFields;

    /** @use HasFactory<ProductFactory> */
    use HasFactory;

    use RecordsHistory;

    /** No date cast: the product form is handed the raw value. */
    protected array $activity_dates = ['start_sell', 'end_sell'];

    protected array $activity_labels = [
        'product_type_id' => 'Producttype',
        'brand_id' => 'Merk',
        'model' => 'Model',
        'description' => 'Beschrijving',
        'start_sell' => 'Verkocht sinds',
        'end_sell' => 'Einde verkoop',
        'typical_certificate_days' => 'Typische certificeringstermijn (dagen)',
        'retail_price' => 'Verkoopprijs',
        'purchase_price' => 'Inkoopprijs',
        'part_no' => 'Artikelnummer',
        'bundle' => 'Bundel',
        'registable' => 'Registreerbaar',
        'active' => 'Actief',
        'warranty' => 'Garantie',
        'ledger_account_id' => 'Grootboekrekening',
    ];

    protected array $activity_relations = [
        'product_type_id' => ['productType', 'name'],
        'brand_id' => ['brand', 'name'],
        'ledger_account_id' => ['ledgerAccount', 'label'],
    ];

    protected array $activity_formats = [
        'retail_price' => [Money::class, 'euros'],
        'purchase_price' => [Money::class, 'euros'],
    ];

    /** The product page shows both prices only to whoever may see them, and so does the trail. */
    protected array $activity_permissions = [
        'retail_price' => 'product.view_prices',
        'purchase_price' => 'product.view_prices',
    ];

    /**
     * The suppliers it was bought from at the moment it was deleted. Its links to
     * them are taken off before it goes, so they are read just before.
     *
     * @var array<int, Supplier>
     */
    private array $linked_before_delete = [];

    /**
     * A product that goes is taken out of every parts list it was in, and its own
     * parts list goes with it, and it is taken off every supplier it was bought
     * from. Each line and link is taken off one by one rather than with a single
     * query, or left behind (the supplier links are a morph pivot the database
     * knows nothing about), so that both sides say so on their timeline, and why.
     */
    protected static function booted(): void
    {
        static::deleting(function (Product $product): void {
            $components = app(ProductComponentAction::class);

            Productable::with(['product.brand', 'childProduct.brand', 'productRelation'])
                ->where('productable_type', Product::class)
                ->where(fn ($rows) => $rows->where('productable_id', $product->id)->orWhere('product_id', $product->id))
                ->get()
                ->each(fn (Productable $row) => $components->remove($row, $product));

            $links = app(SupplierLinkAction::class);
            $product->linked_before_delete = $product->suppliers()->get()->all();

            foreach ($product->linked_before_delete as $supplier) {
                $links->unlink($product, $supplier);
            }
        });
    }

    /**
     * A product that is deleted takes its own timeline with it; the suppliers it
     * was bought from say so.
     */
    public function historyTimelines(string $action): array
    {
        return $action === 'deleted' ? $this->linked_before_delete : [];
    }

    public function historyLabel(): ?string
    {
        return 'Product ' . ($this->display_name ?? $this->id);
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

        if ($user->can('list', self::class)) {
            return $query;
        }

        if ($user->hasPermission('product.read.relevant.serviceorder')) {
            return $query->whereKey($user->relevantProductIds());
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
        'ledger_account_id',
    ];

    /**
     * Hidden because a product travels along on many pages. The product pages
     * make them visible for whoever may see them, like $activity_permissions.
     */
    protected $hidden = [
        'retail_price',
        'purchase_price',
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

    /** The revenue account it is booked on once products themselves are invoiced. */
    public function ledgerAccount(): BelongsTo
    {
        return $this->belongsTo(LedgerAccount::class);
    }

    /**
     * How a product is named to a person: the brand followed by the model, which
     * is what every screen showing a product already spells out by hand. There is
     * no name column, so anything reading one gets nothing.
     *
     * Deliberately not appended: it costs a brand lookup, and most payloads
     * carrying a product do not show its name.
     *
     * A brand loaded before the brand changed is looked up again, or the history
     * of that very change would name the product by its old brand.
     */
    public function getDisplayNameAttribute(): ?string
    {
        if ($this->relationLoaded('brand') && (string) $this->brand?->getKey() !== (string) $this->brand_id) {
            $this->unsetRelation('brand');
        }

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

    /**
     * The materials that come along when this product is put on a quote, and
     * whether each one comes without asking, is asked, or is only suggested.
     */
    public function materialDefaults()
    {
        return $this->hasMany(ProductMaterialDefault::class)->orderBy('position')->orderBy('id');
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

    /**
     * Products holding one of these values for every kenmerk among them: more
     * values of one kenmerk widen the list, another kenmerk narrows it.
     *
     * @param  array<int, int>  $value_ids
     */
    public function scopeWithAttributeValues(Builder $query, array $value_ids): Builder
    {
        $by_attribute = ProductAttributeValue::whereKey($value_ids)->get(['id', 'product_attribute_id'])
            ->groupBy('product_attribute_id');

        foreach ($by_attribute as $values) {
            $query->whereHas('productAttributeValueables', fn ($valueables) => $valueables
                ->whereIn('product_attribute_value_id', $values->modelKeys()));
        }

        return $query;
    }

    /** Products whose number for this kenmerk lies between min and max, both included; null leaves an end open. */
    public function scopeWithAttributeBetween(Builder $query, int $attribute_id, ?float $min, ?float $max): Builder
    {
        return $query->whereHas('productAttributeValueables', fn ($valueables) => $valueables
            ->whereIn('product_attribute_value_id', ProductAttributeValue::query()
                ->where('product_attribute_id', $attribute_id)
                ->when($min !== null, fn ($values) => $values->where('number', '>=', $min))
                ->when($max !== null, fn ($values) => $values->where('number', '<=', $max))
                ->select('id')));
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

    /**
     * A product as the machine forms pick it. Bundle and registable decide whether
     * the form asks for a serienummer; the two typical durations set the first
     * keuring of the machine being registered.
     */
    public function toMachineOption(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->brand->name . ' ' . $this->model . ' (' . $this->productType->name . ')',
            'bundle' => $this->bundle,
            'registable' => $this->registable,
            'typical_certificate_days' => $this->typical_certificate_days,
            'product_type_typical_certificate_days' => $this->productType->typical_certificate_days,
        ];
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

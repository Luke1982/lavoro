<?php

namespace App\Models;

use App\Enums\DefaultMaterialPrompts;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphPivot;

/**
 * What belongs with a product, one row each: a part of a bundle (another
 * product), a standard material that comes along when the product is offered
 * on a quote, or, under a werkbon taak, the aantallen that taak chose. A
 * material per piece can be a part of a metre, so the quantity is a number
 * with decimals for every row; a part still only ever holds whole pieces.
 */
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
        'prompt',
        'position',
    ];

    protected $casts = [
        'is_required' => 'boolean',
        'flex_quantity' => 'boolean',
        'quantity' => 'float',
        'prompt' => DefaultMaterialPrompts::class,
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

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class, 'productable_id');
    }

    /**
     * A line of a product's parts list. The same table also holds the aantallen a
     * werkbon taak chose, under another productable_type, and those are not.
     */
    public function isComponent(): bool
    {
        return $this->productable_type === Product::class && $this->childProduct !== null;
    }

    /** A material that comes along when the product is offered on a quote. */
    public function isMaterialDefault(): bool
    {
        return $this->productable_type === Material::class;
    }

    /**
     * A material default as the product page lists it and as the question on a
     * quote asks it. Needs material.usageUnit loaded.
     *
     * @return array{id: int, material_id: int, name: string, unit: ?string, price: float, quantity: float, prompt: string}
     */
    public function toMaterialDefaultOption(): array
    {
        return [
            'id' => $this->id,
            'material_id' => $this->productable_id,
            'name' => $this->material->name,
            'unit' => $this->material->usageUnit?->name,
            'price' => (float) ($this->material->price ?? 0),
            'quantity' => $this->quantity,
            'prompt' => $this->prompt->value,
        ];
    }
}

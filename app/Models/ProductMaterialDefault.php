<?php

namespace App\Models;

use App\Enums\DefaultMaterialPrompts;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A material that belongs with a product when it is offered: the piping, the
 * bracket and the condensate pump of an airco unit. How it comes along — always,
 * asked and ticked on, or only suggested — is the prompt.
 */
class ProductMaterialDefault extends Model
{
    protected $fillable = ['product_id', 'material_id', 'quantity', 'prompt', 'position'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'prompt' => DefaultMaterialPrompts::class,
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(Material::class);
    }

    /**
     * As the product page lists it and as the question on a quote asks it.
     * Needs material.usageUnit loaded.
     *
     * @return array{id: int, material_id: int, name: string, unit: ?string, price: float, quantity: float, prompt: string}
     */
    public function toOption(): array
    {
        return [
            'id' => $this->id,
            'material_id' => $this->material_id,
            'name' => $this->material->name,
            'unit' => $this->material->usageUnit?->name,
            'price' => (float) ($this->material->price ?? 0),
            'quantity' => $this->quantity,
            'prompt' => $this->prompt->value,
        ];
    }
}

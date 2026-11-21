<?php

namespace App\Actions\Products;

use App\Domain\Signals\Products\ProductComponentAdded;
use App\Domain\Signals\Products\ProductComponentChanged;
use App\Domain\Signals\Products\ProductComponentRemoved;
use App\Domain\Signals\Products\ProductComponentSignal;
use App\Domain\Signals\Signals;
use App\Models\Product;
use App\Models\Productable;

/**
 * Puts a product on another product's parts list, changes how it is on it, or
 * takes it off. A line of that list is a pivot row, which tells nobody it
 * changed, so this is the one way it changes, each saying what it was before
 * and after.
 */
class ProductComponentAction
{
    /** @param  array{product_relation_id?: ?int, quantity: int, flex_quantity?: bool, is_required?: bool}  $terms */
    public function add(Product $product, Product $component, array $terms): Productable
    {
        $row = Productable::create([
            'product_id' => $product->id,
            'productable_type' => Product::class,
            'productable_id' => $component->id,
            'product_relation_id' => $terms['product_relation_id'] ?? null,
            'quantity' => $terms['quantity'],
            'flex_quantity' => $terms['flex_quantity'] ?? false,
            'is_required' => $terms['is_required'] ?? false,
        ]);

        Signals::dispatch(new ProductComponentAdded($product, $component, $row));

        return $row;
    }

    /**
     * Changes the terms of a line. Which product it is on, and which part it
     * is, stay as they are.
     *
     * @param  array{product_relation_id?: ?int, quantity?: int, flex_quantity?: bool, is_required?: bool}  $terms
     */
    public function change(Productable $row, array $terms): void
    {
        $row->fill(array_intersect_key($terms, ProductComponentSignal::LABELS));

        $fields = [];

        foreach (array_keys($row->getDirty()) as $field) {
            $fields[$field] = [$row->getOriginal($field), $row->getAttribute($field)];
        }

        $row->save();

        if ($fields !== [] && $row->isComponent()) {
            Signals::dispatch(new ProductComponentChanged($row->product, $row->childProduct, $row, $fields));
        }
    }

    /**
     * Takes a line off. $deleted is the product whose deletion takes it off,
     * the part or the product it was on, so the entry says why.
     */
    public function remove(Productable $row, ?Product $deleted = null): void
    {
        $row->delete();

        if ($row->isComponent() && $row->product) {
            Signals::dispatch(new ProductComponentRemoved($row->product, $row->childProduct, $row, $deleted));
        }
    }
}

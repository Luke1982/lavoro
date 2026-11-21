<?php

namespace App\Actions\Products;

use App\Domain\Products\AttributeNumber;
use App\Domain\Signals\Products\ProductAttributeFormatChanged;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
use App\Models\Product;
use App\Models\ProductAttribute;
use App\Models\ProductAttributeValue;
use App\Models\ProductAttributeValueable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Makes a kenmerk hold numbers or text, and gives a numeric one its unit; text
 * has none. A value has a number exactly when its kenmerk is numeric.
 *
 * Becoming numeric reads the number out of every value it already has: "10",
 * "10,5" and "10 kW" for the unit kW all count. Values that only differed in
 * how they were written ("10 kW" and "10kW") become one, and the products on
 * them move along. A value that is not a number refuses the whole change, so
 * nothing is lost. Changing the unit rewrites the labels, not the numbers:
 * 1500 W stays 1500 when the unit becomes kW.
 */
class ChangeProductAttributeFormatAction
{
    public function handle(ProductAttribute $attribute, string $value_type, ?string $unit): void
    {
        $old_value_type = $attribute->value_type;
        $old_unit = $attribute->unit;

        $attribute->value_type = $value_type;
        $attribute->unit = $attribute->isNumeric() && !blank($unit) ? trim($unit) : null;

        if (!$attribute->isDirty()) {
            return;
        }

        DB::transaction(function () use ($attribute, $old_value_type, $old_unit) {
            $attribute->save();
            $merged = 0;

            if ($attribute->isNumeric()) {
                $merged = $this->relabel($attribute);
            } else {
                $attribute->values()->update(['number' => null]);
            }

            Signals::dispatch(new ProductAttributeFormatChanged(
                $attribute,
                $old_value_type,
                $old_unit,
                $merged,
                $attribute->isNumeric() ? $this->productsHolding($attribute) : [],
            ));
        });
    }

    /**
     * Folds values that are the same number into the first of them, then writes
     * every value left from its number in the current unit. Folding comes first:
     * "10" cannot become "10 kW" while "10 kW" is still a value of its own.
     *
     * @return int how many values were folded into another
     */
    private function relabel(ProductAttribute $attribute): int
    {
        $values = $attribute->values()->get();
        $numbers = $this->numbersOf($attribute, $values);
        $kept = [];

        foreach ($values as $value) {
            $key = (string) $numbers[$value->id];

            if (!isset($kept[$key])) {
                $kept[$key] = $value;

                continue;
            }

            ProductAttributeValueable::where('product_attribute_value_id', $value->id)
                ->update(['product_attribute_value_id' => $kept[$key]->id]);
            $value->delete();
        }

        foreach ($kept as $value) {
            $value->update($attribute->numberFields($numbers[$value->id]));
        }

        return $values->count() - count($kept);
    }

    /**
     * The products holding a value of this kenmerk: relabelled, every one of
     * them now reads its value differently. Becoming text keeps every label.
     *
     * @return array<int, Product>
     */
    private function productsHolding(ProductAttribute $attribute): array
    {
        return Product::query()
            ->whereHas('productAttributeValueables', fn ($valueables) => $valueables
                ->whereIn('product_attribute_value_id', $attribute->values()->select('id')))
            ->get(['products.id'])
            ->all();
    }

    /**
     * @param  Collection<int, ProductAttributeValue>  $values
     * @return array<int, float> value id → number
     */
    private function numbersOf(ProductAttribute $attribute, Collection $values): array
    {
        $numbers = [];
        $not_numbers = [];

        foreach ($values as $value) {
            $number = $value->number ?? AttributeNumber::parse($value->value, $attribute->unit);

            if ($number === null) {
                $not_numbers[] = '"' . $value->value . '"';
            } else {
                $numbers[$value->id] = $number;
            }
        }

        if ($not_numbers !== []) {
            throw new Refusal(
                $attribute->name . ' kan geen getal worden: ' . implode(', ', $not_numbers)
                . (count($not_numbers) === 1 ? ' is' : ' zijn') . ' geen getal'
                . ($attribute->unit ? ' in ' . $attribute->unit : '') . '. Pas die eerst aan of verwijder ze.'
            );
        }

        return $numbers;
    }
}

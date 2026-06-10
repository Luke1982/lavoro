<?php

namespace App\Http\Requests;

use App\Models\ProductAttributeValue;
use App\Rules\DbRange;

/**
 * @property int $product_type_id
 * @property int $brand_id
 * @property string $model
 * @property string|null $description
 * @property string|null $start_sell
 * @property string|null $end_sell
 * @property string|null $origin
 * @property int|null $typical_certificate_days
 * @property string|null $retail_price
 * @property string|null $purchase_price
 * @property string|null $part_no
 * @property bool|null $bundle
 *
 * @method mixed input(string $key = null, mixed $default = null)
 */
class ProductStoreRequest extends ProductRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $brandId = $this->input('brand_id');
        $typeId = $this->input('product_type_id');
        $modelName = $this->input('model');
        $startSell = $this->input('start_sell');

        return [
            'product_type_id' => ['required', 'exists:product_types,id'],
            'brand_id' => ['required', 'exists:brands,id'],
            'model' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_sell' => ['nullable', 'date'],
            'end_sell' => array_filter([
                'nullable',
                'date',
                $startSell ? 'after_or_equal:start_sell' : null,
                $this->endSellOverlapClosure($brandId, $typeId, $modelName, $startSell),
            ]),
            'origin' => ['nullable', 'string'],
            'typical_certificate_days' => ['nullable', 'integer', 'min:1', DbRange::int()],
            'retail_price' => ['nullable', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'purchase_price' => ['nullable', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'part_no' => ['nullable', 'string', 'max:255'],
            'bundle' => ['nullable', 'boolean'],
            'active' => ['nullable', 'boolean'],
            'warranty' => ['nullable', 'string', 'max:255'],
            'attributes' => ['sometimes', 'array'],
            'attributes.*.product_attribute_id' => ['required', 'integer', 'exists:product_attributes,id'],
            'attributes.*.product_attribute_value_id' => [
                'required',
                'integer',
                'exists:product_attribute_values,id',
                function (string $attribute, mixed $value, \Closure $fail) {
                    $index = explode('.', $attribute)[1];
                    $attrId = $this->input("attributes.{$index}.product_attribute_id");
                    if (
                        ! ProductAttributeValue::where('id', $value)
                            ->where('product_attribute_id', $attrId)
                            ->exists()
                    ) {
                        $fail('De geselecteerde waarde hoort niet bij het opgegeven kenmerk.');
                    }
                },
            ],
        ];
    }
}

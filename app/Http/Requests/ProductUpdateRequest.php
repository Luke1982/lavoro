<?php

namespace App\Http\Requests;

use App\Models\Productable;
use App\Models\Product;
use App\Rules\DbRange;
use Illuminate\Validation\Validator;

/**
 * @property int|null    $product_type_id
 * @property int|null    $brand_id
 * @property string|null $model
 * @property string|null $description
 * @property string|null $start_sell
 * @property string|null $end_sell
 * @property int|null    $typical_certificate_days
 * @property string|null $retail_price
 * @property string|null $purchase_price
 * @property string|null $part_no
 * @property bool|null   $bundle
 * @method \App\Models\Product route(string $key = null)
 * @method mixed input(string $key = null, mixed $default = null)
 */
class ProductUpdateRequest extends ProductRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $product   = $this->route('product');
        $productId = $product?->id;

        // Fall back to the product's existing values so partial PATCHes
        // (e.g. only brand_id) still pass the overlap closure correctly.
        $brandId   = $this->input('brand_id', $product?->brand_id);
        $typeId    = $this->input('product_type_id', $product?->product_type_id);
        $modelName = $this->input('model', $product?->model);
        $startSell = $this->input('start_sell', $product?->start_sell);

        return [
            'product_type_id' => ['sometimes', 'required', 'exists:product_types,id'],
            'brand_id'        => ['sometimes', 'required', 'exists:brands,id'],
            'model'           => ['sometimes', 'required', 'string', 'max:255'],
            'description'     => ['sometimes', 'nullable', 'string'],
            'start_sell'      => ['sometimes', 'nullable', 'date'],
            'end_sell'        => array_filter([
                'sometimes',
                'nullable',
                'date',
                $startSell ? 'after_or_equal:start_sell' : null,
                $this->endSellOverlapClosure($brandId, $typeId, $modelName, $startSell, $productId),
            ]),
            'typical_certificate_days' => ['sometimes', 'nullable', 'integer', 'min:1', DbRange::int()],
            'retail_price'             => ['sometimes', 'nullable', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'purchase_price'           => ['sometimes', 'nullable', 'numeric', 'min:0', DbRange::decimal(10, 2)],
            'part_no'                  => ['sometimes', 'nullable', 'string', 'max:255'],
            'bundle'                   => ['sometimes', 'nullable', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                if (!$this->boolean('bundle')) {
                    return;
                }

                $productId = $this->route('product')?->id;
                if (!$productId) {
                    return;
                }

                // Check if any parent of this product is also a bundle.
                $bundleParent = Productable::where('product_id', $productId)
                    ->where('productable_type', Product::class)
                    ->join('products', 'products.id', '=', 'productables.productable_id')
                    ->where('products.bundle', true)
                    ->select('products.model')
                    ->first();

                if ($bundleParent) {
                    $bundleMsg = 'Dit product kan niet als bundel worden ingesteld omdat'
                        . ' het al onderdeel is van het gebundelde product "' . $bundleParent->model . '".';
                    $validator->errors()->add('bundle', $bundleMsg);
                    return;
                }

                // Check if any child of this product is also a bundle.
                $hasBundleChild = Productable::where('productables.productable_type', Product::class)
                    ->where('productables.productable_id', $productId)
                    ->join('products', 'products.id', '=', 'productables.product_id')
                    ->where('products.bundle', true)
                    ->exists();

                if ($hasBundleChild) {
                    $childMsg = 'Dit product kan niet als bundel worden ingesteld omdat'
                        . ' een van zijn onderdelen al een gebundeld product is.';
                    $validator->errors()->add('bundle', $childMsg);
                }
            },
        ];
    }
}

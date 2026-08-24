<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Models\Productable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ProductableStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Productable::class);
    }

    /**
     * A flex part settles its aantal when the bundle is sold, so the catalogue number is
     * pinned rather than left at whatever stood in the field when the switch was flipped.
     */
    protected function prepareForValidation(): void
    {
        if ($this->boolean('flex_quantity')) {
            $this->merge(['quantity' => 1]);
        }
    }

    public function rules(): array
    {
        return [
            'product_id' => ['required', 'integer', 'exists:products,id'],
            'child_product_id' => ['required', 'integer', 'exists:products,id', 'different:product_id'],
            'product_relation_id' => ['nullable', 'integer', 'exists:product_relations,id'],
            'quantity' => ['required', 'integer', 'min:1'],
            'flex_quantity' => ['boolean'],
            'is_required' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                $child = Product::find($this->child_product_id);
                if ($child?->bundle) {
                    $validator->errors()->add(
                        'child_product_id',
                        'Een gebundeld product kan niet als onderdeel van een ander product worden toegevoegd.'
                    );

                    return;
                }

                $parent = Product::find($this->product_id);
                if ($parent?->bundle) {
                    $childIsBundle = $child?->bundle ?? false;
                    if ($childIsBundle) {
                        $validator->errors()->add(
                            'child_product_id',
                            'Een gebundeld product kan geen gebundeld product als onderdeel hebben.'
                        );
                    }
                }
            },
        ];
    }
}

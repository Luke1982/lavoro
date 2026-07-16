<?php

namespace App\Http\Requests;

use App\Models\Asset;
use App\Models\Productable;
use App\Rules\UniqueSerialForProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class AssetChildStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('attachChild', $this->route('asset'));
    }

    public function rules(): array
    {
        $productable = Productable::find($this->input('productable_id'));

        return [
            'productable_id' => ['required', 'integer', 'exists:productables,id'],
            'serial_number' => [
                'required',
                'string',
                'max:255',
                UniqueSerialForProduct::forProduct($productable?->productable_id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'serial_number.required' => 'Serienummer is verplicht.',
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isNotEmpty()) {
                    return;
                }

                /** @var Asset $asset */
                $asset = $this->route('asset');
                $productable = Productable::find($this->productable_id);

                if ($productable->product_id !== $asset->product_id) {
                    $validator->errors()->add(
                        'productable_id',
                        'Dit onderdeel hoort niet bij het product van deze machine.'
                    );

                    return;
                }

                $usedCount = Asset::where('parent_asset_id', $asset->id)
                    ->where('productable_id', $productable->id)
                    ->count();

                if ($usedCount >= $productable->quantity) {
                    $validator->errors()->add(
                        'productable_id',
                        'Het maximale aantal (' . $productable->quantity . ') voor dit onderdeel is al bereikt.'
                    );
                }
            },
        ];
    }
}

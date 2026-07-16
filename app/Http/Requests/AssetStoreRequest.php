<?php

namespace App\Http\Requests;

use App\Models\Product;
use App\Rules\UniqueSerialForProduct;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AssetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }

        return $user->hasPermission('asset.create');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('serial_number') && $this->input('serial_number') === '') {
            $this->merge(['serial_number' => null]);
        }
    }

    public function messages(): array
    {
        return [
            'serial_number.required' => 'Serienummer is verplicht.',
            'child_assets.*.serial_number.required_with' => 'Serienummer is verplicht.',
        ];
    }

    public function rules(): array
    {
        $product = Product::find($this->input('product_id'));
        $is_bundle = $product?->bundle ?? false;

        $serial_rules = $is_bundle
            ? ['nullable', 'string', 'max:255']
            : [
                'required',
                'string',
                'max:255',
                UniqueSerialForProduct::forProduct($this->input('product_id')),
            ];

        return [
            'product_id' => ['required', 'exists:products,id'],
            'customer_id' => ['required', 'exists:customers,id'],
            'location_id' => [
                'nullable',
                Rule::exists('locations', 'id')->where(fn ($q) => $q->where('customer_id', $this->input('customer_id'))),
            ],
            'serial_number' => $serial_rules,
            'is_active' => ['nullable', 'boolean'],
            'next_service_date' => ['nullable', 'date'],
            'date_in_service' => ['nullable', 'date'],
            'child_assets' => ['nullable', 'array'],
            'child_assets.*.productable_id' => ['required_with:child_assets', 'integer', 'exists:productables,id'],
            'child_assets.*.serial_number' => ['required_with:child_assets', 'string', 'max:255'],
        ];
    }
}

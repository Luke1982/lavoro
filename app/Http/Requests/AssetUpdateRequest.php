<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class AssetUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        return $user->isAdmin() || $user->hasPermission('asset.update');
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
            'serial_number.unique'    => 'Er bestaat al een machine met dit serienummer voor dit product.',
            'serial_number.required'  => 'Serienummer is verplicht.',
        ];
    }

    public function rules(): array
    {
        $product   = Product::find($this->input('product_id'));
        $is_bundle = $product?->bundle ?? false;

        $serial_rules = $is_bundle
            ? ['nullable', 'string', 'max:255']
            : [
                'required',
                'string',
                'max:255',
                Rule::unique('assets', 'serial_number')
                    ->ignore($this->route('asset')?->id)
                    ->where(fn($q) => $q->where('product_id', $this->input('product_id'))),
            ];

        return [
            'product_id'        => 'required|exists:products,id',
            'serial_number'     => $serial_rules,
            'next_service_date' => 'nullable|date',
            'customer_id'       => 'required|exists:customers,id',
            'status'            => 'required|in:Actief,Niet actief',
        ];
    }
}

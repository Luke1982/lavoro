<?php

namespace App\Http\Requests;

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

    public function rules(): array
    {
        return [
            'product_id'    => ['required', 'exists:products,id'],
            'customer_id'   => ['required', 'exists:customers,id'],
            'serial_number' => [
                'required',
                'string',
                'max:255',
                Rule::unique('assets', 'serial_number')->where(function ($q) {
                    return $q->where('product_id', request()->input('product_id'));
                }),
            ],
            'is_active'     => ['nullable', 'boolean'],
            'next_service_date' => ['nullable', 'date'],
            'child_assets'                    => ['nullable', 'array'],
            'child_assets.*.productable_id'   => ['required_with:child_assets', 'integer', 'exists:productables,id'],
            'child_assets.*.serial_number'    => ['required_with:child_assets', 'string', 'max:255'],
        ];
    }
}

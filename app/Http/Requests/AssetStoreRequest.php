<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class AssetStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        return $user->isAdmin() || $user->hasPermission('asset.create');
    }

    public function rules(): array
    {
        return [
            'product_id'    => ['required', 'exists:products,id'],
            'customer_id'   => ['required', 'exists:customers,id'],
            'serial_number' => ['nullable', 'string', 'max:255'],
            'is_active'     => ['nullable', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ProductableStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && $user->hasPermission('productable.create');
    }

    public function rules(): array
    {
        return [
            'product_id'          => ['required', 'integer', 'exists:products,id'],
            'child_product_id'    => ['required', 'integer', 'exists:products,id', 'different:product_id'],
            'product_relation_id' => ['nullable', 'integer', 'exists:product_relations,id'],
            'quantity'            => ['required', 'integer', 'min:1'],
            'is_required'         => ['boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class ProductRelationStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && $user->hasPermission('productrelation.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}

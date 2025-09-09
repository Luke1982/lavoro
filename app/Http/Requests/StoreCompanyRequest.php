<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCompanyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Adjust with auth logic if needed
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'address_line1' => 'nullable|string|max:255',
            'address_line2' => 'nullable|string|max:255',
            'postal_code' => 'nullable|string|max:32',
            'city' => 'nullable|string|max:255',
            'country' => 'nullable|string|size:2',
            'is_main' => 'sometimes|boolean',
            'logo' => 'nullable|image|max:2048',
        ];
    }
}

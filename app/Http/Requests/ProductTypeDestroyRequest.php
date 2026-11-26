<?php

namespace App\Http\Requests;

use App\Models\ProductType;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing a product type, with its subtypes, asks for producttype.delete.
 */
class ProductTypeDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('delete', ProductType::class);
    }

    public function rules(): array
    {
        return [];
    }
}

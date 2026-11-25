<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ProductSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('list', Product::class);
    }

    public function rules(): array
    {
        return ['q' => 'nullable|string|max:255'];
    }
}

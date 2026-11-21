<?php

namespace App\Http\Requests;

use App\Models\Product;
use Illuminate\Foundation\Http\FormRequest;

class ProductDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', Product::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Brand;
use Illuminate\Foundation\Http\FormRequest;

class BrandDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('delete', Brand::class) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}

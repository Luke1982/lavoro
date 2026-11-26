<?php

namespace App\Http\Requests;

use App\Models\MaterialCategory;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing a materiaalcategorie asks for materialcategory.delete.
 */
class MaterialCategoryDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('delete', MaterialCategory::class);
    }

    public function rules(): array
    {
        return [];
    }
}

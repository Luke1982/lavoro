<?php

namespace App\Http\Requests;

use App\Models\MaterialCategory;
use Illuminate\Foundation\Http\FormRequest;

/** Making a materiaalcategorie asks for create, changing the one in the route for update. */
class MaterialCategoryStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can($this->route('materialcategory') ? 'update' : 'create', MaterialCategory::class);
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'icon' => 'nullable|string|max:100',
        ];
    }
}

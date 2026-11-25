<?php

namespace App\Http\Requests;

use App\Models\ProductRelation;
use Illuminate\Foundation\Http\FormRequest;

class ProductRelationReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', ProductRelation::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\ProductRelation;
use Illuminate\Foundation\Http\FormRequest;

class ProductRelationStoreUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $productRelation = $this->route('productrelation');

        return $productRelation
            ? $this->user()->can('update', $productRelation)
            : $this->user()->can('create', ProductRelation::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}

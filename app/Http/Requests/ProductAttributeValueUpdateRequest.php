<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAttributeValue;
use Illuminate\Foundation\Http\FormRequest;

class ProductAttributeValueUpdateRequest extends FormRequest
{
    use ValidatesAttributeValue;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('productattributevalue')->productAttribute);
    }

    public function rules(): array
    {
        $pav = $this->route('productattributevalue');

        return [
            'value' => $this->attributeValueRules($pav->productAttribute, $pav->id),
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderAttachMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $material = $this->route('material');
        $quantity_rule = ($material instanceof Material && $material->divisable)
            ? 'required|numeric|min:0.01'
            : 'required|integer|min:1';

        return [
            'quantity'  => $quantity_rule,
            'unforseen' => 'sometimes|boolean',
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.integer' => 'Dit materiaal is niet deelbaar. Voer een heel getal in.',
            'quantity.numeric' => 'Voer een geldig aantal in.',
            'quantity.min'     => 'Het aantal moet minimaal :min zijn.',
        ];
    }
}

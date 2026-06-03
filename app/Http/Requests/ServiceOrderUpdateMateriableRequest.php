<?php

namespace App\Http\Requests;

use App\Models\Material;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderUpdateMateriableRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $serviceorder = $this->route('serviceorder');
        $materiable_id = $this->route('materiable_id');

        $material = null;
        if ($serviceorder && $materiable_id) {
            $record = $serviceorder->materials()
                ->newPivotQuery()
                ->where('materiables.id', $materiable_id)
                ->first();
            if ($record) {
                $material = Material::find($record->material_id);
            }
        }

        $quantity_rule = ($material && !$material->divisable)
            ? 'sometimes|integer|min:1'
            : 'sometimes|numeric|min:0.01';

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

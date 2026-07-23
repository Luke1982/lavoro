<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesMateriableOwner;
use Illuminate\Foundation\Http\FormRequest;

class FreeformMaterialUpdateRequest extends FormRequest
{
    use ResolvesMateriableOwner;

    public function authorize(): bool
    {
        $freeform = $this->route('freeform_material');

        return $freeform !== null
            && $this->ownerMatches($freeform->freeformmateriable_type, $freeform->freeformmateriable_id)
            && $this->user()->can('update', $freeform);
    }

    public function rules(): array
    {
        return [
            'quantity' => ['sometimes', 'numeric', 'min:0.01'],
            'description' => ['sometimes', 'string', 'max:255'],
            'unforseen' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'quantity.numeric' => 'Voer een geldig aantal in.',
            'quantity.min' => 'Het aantal moet minimaal :min zijn.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesMateriableOwner;
use App\Models\FreeformMaterial;
use Illuminate\Foundation\Http\FormRequest;

class FreeformMaterialStoreRequest extends FormRequest
{
    use ResolvesMateriableOwner;

    /**
     * Writing a free line, on a werkbon this person can reach.
     *
     * The werkbon is asked whether it may be seen rather than whether materials
     * may be attached to it: booking only free lines is a role of its own (a
     * monteur has freeformmaterial.create without materiable.create), and the
     * field on the screen offers exactly that.
     */
    public function authorize(): bool
    {
        return $this->user()->can('create', FreeformMaterial::class) && $this->authorizeMateriable('view');
    }

    /** A line typed by hand is onvoorzien unless said otherwise: it was not in the plan. */
    protected function prepareForValidation(): void
    {
        $this->mergeIfMissing(['unforseen' => true]);
    }

    public function rules(): array
    {
        return [
            'quantity' => ['required', 'numeric', 'min:0.01'],
            'description' => ['required', 'string', 'max:255'],
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

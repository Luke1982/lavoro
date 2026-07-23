<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesMateriableOwner;
use Illuminate\Foundation\Http\FormRequest;

class FreeformMaterialDestroyRequest extends FormRequest
{
    use ResolvesMateriableOwner;

    public function authorize(): bool
    {
        $freeform = $this->route('freeform_material');

        return $freeform !== null
            && $this->ownerMatches($freeform->freeformmateriable_type, $freeform->freeformmateriable_id)
            && $this->user()->can('delete', $freeform);
    }

    public function rules(): array
    {
        return [];
    }
}

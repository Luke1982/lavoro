<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ResolvesMateriableOwner;
use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderDetachMaterialRequest extends FormRequest
{
    use ResolvesMateriableOwner;

    public function authorize(): bool
    {
        return $this->authorizeMateriable('detachMaterial');
    }

    public function rules(): array
    {
        return [];
    }
}

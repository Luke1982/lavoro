<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class FreeformMaterialDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('freeform_material'));
    }

    public function rules(): array
    {
        return [];
    }
}

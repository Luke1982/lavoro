<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderDetachMaterialRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('detachMaterial', $this->route('serviceorder'));
    }

    public function rules(): array
    {
        return [];
    }
}

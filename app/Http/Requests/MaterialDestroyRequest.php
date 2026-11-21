<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MaterialDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('material.delete') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}

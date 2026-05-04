<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProductableDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('productable'));
    }

    public function rules(): array
    {
        return [];
    }
}

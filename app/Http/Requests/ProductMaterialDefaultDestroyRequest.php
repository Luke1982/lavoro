<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

class ProductMaterialDefaultDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageDefaults', Quote::class);
    }

    public function rules(): array
    {
        return [];
    }
}

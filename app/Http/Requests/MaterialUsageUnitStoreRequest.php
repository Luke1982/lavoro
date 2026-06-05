<?php

namespace App\Http\Requests;

use App\Models\MaterialUsageUnit;
use Illuminate\Foundation\Http\FormRequest;

class MaterialUsageUnitStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', MaterialUsageUnit::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}

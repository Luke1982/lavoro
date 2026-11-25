<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

class SupplierSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Supplier::class);
    }

    public function rules(): array
    {
        return ['q' => 'nullable|string|max:255'];
    }
}

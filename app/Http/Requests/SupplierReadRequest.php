<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

class SupplierReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $supplier = $this->route('supplier');
        return $supplier
            ? $this->user()->can('view', $supplier)
            : $this->user()->can('viewAny', Supplier::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}

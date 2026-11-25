<?php

namespace App\Http\Requests;

use App\Models\Customer;
use Illuminate\Foundation\Http\FormRequest;

class CustomerSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', Customer::class);
    }

    public function rules(): array
    {
        return ['q' => 'nullable|string|max:255'];
    }
}

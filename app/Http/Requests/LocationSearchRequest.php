<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;

class LocationSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('pickForCustomer', Location::class);
    }

    public function rules(): array
    {
        return [
            'q' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }
}

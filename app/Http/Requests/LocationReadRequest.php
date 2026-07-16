<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;

class LocationReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        $location = $this->route('location');

        return $location
            ? $this->user()->can('view', $location)
            : $this->user()->can('viewAny', Location::class);
    }

    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'customer_id' => ['sometimes', 'nullable', 'integer', 'exists:customers,id'],
        ];
    }
}

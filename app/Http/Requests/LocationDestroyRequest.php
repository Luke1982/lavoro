<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('location'));
    }

    public function rules(): array
    {
        $location = $this->route('location');

        return [
            'disposition' => ['sometimes', 'in:detach,move'],
            'target_location_id' => [
                'required_if:disposition,move',
                Rule::exists('locations', 'id')
                    ->where(fn ($q) => $q->where('customer_id', $location->customer_id)),
                Rule::notIn([$location->id]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'target_location_id.required_if' => 'Kies een locatie om de machines naartoe te verplaatsen.',
            'target_location_id.not_in' => 'Je kunt machines niet naar dezelfde locatie verplaatsen.',
        ];
    }
}

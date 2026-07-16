<?php

namespace App\Http\Requests;

use App\Models\Location;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LocationStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Location::class);
    }

    public function rules(): array
    {
        return [
            'customer_id' => ['required', 'exists:customers,id'],
            'title' => ['required', 'string', 'max:255'],
            'location_code' => [
                'nullable', 'string', 'max:255',
                Rule::unique('locations')->where(fn ($q) => $q->where('customer_id', $this->input('customer_id'))),
            ],
            'address' => ['required', 'string', 'max:255'],
            'postal_code' => ['nullable', 'regex:/^\d{4}\s?[A-Za-z]{2}$/'],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'title.required' => 'Titel is verplicht.',
            'location_code.required' => 'Locatiecode is verplicht.',
            'location_code.unique' => 'Deze locatiecode bestaat al voor deze klant.',
            'address.required' => 'Adres is verplicht.',
            'postal_code.regex' => 'Postcode moet 4 cijfers gevolgd door 2 letters zijn (bijv. 1234AB).',
        ];
    }

    public function sanitized(): array
    {
        $data = $this->validated();
        if (!empty($data['postal_code'])) {
            $data['postal_code'] = strtoupper(preg_replace('/\s|-/', '', (string) $data['postal_code']));
        }

        return $data;
    }
}

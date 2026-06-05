<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

class SupplierUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('supplier'));
    }

    public function rules(): array
    {
        return [
            'name'           => ['sometimes', 'required', 'string', 'max:255'],
            'email'          => ['sometimes', 'nullable', 'email', 'max:255'],
            'phone'          => [
                'sometimes', 'nullable',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') return;
                    if (strlen(preg_replace('/\D+/', '', (string) $value)) !== 10) {
                        $fail('Telefoonnummer moet uit precies 10 cijfers bestaan.');
                    }
                },
            ],
            'mobile'         => ['sometimes', 'nullable', 'string', 'max:255'],
            'website'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'address'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'postal_code'    => ['sometimes', 'nullable', 'regex:/^\d{4}\s?[A-Za-z]{2}$/'],
            'city'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'country'        => ['sometimes', 'nullable', 'string', 'max:255'],
            'iban'           => ['sometimes', 'nullable', 'string', 'max:255'],
            'vat_number'     => ['sometimes', 'nullable', 'string', 'max:255'],
            'kvk_number'     => ['sometimes', 'nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required'     => 'Naam is verplicht.',
            'email.email'       => 'Het e-mailadres is ongeldig.',
            'postal_code.regex' => 'Postcode moet 4 cijfers gevolgd door 2 letters zijn (bijv. 1234AB).',
        ];
    }

    public function sanitized(): array
    {
        $data = $this->validated();
        if (array_key_exists('phone', $data) && $data['phone'] !== null) {
            $data['phone'] = preg_replace('/\D+/', '', (string) $data['phone']);
        }
        if (array_key_exists('postal_code', $data) && $data['postal_code'] !== null) {
            $data['postal_code'] = strtoupper(preg_replace('/\s|-/', '', (string) $data['postal_code']));
        }
        return $data;
    }
}

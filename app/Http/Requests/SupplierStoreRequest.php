<?php

namespace App\Http\Requests;

use App\Models\Supplier;
use Illuminate\Foundation\Http\FormRequest;

class SupplierStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', Supplier::class);
    }

    public function rules(): array
    {
        return [
            'name'           => ['required', 'string', 'max:255'],
            'email'          => ['nullable', 'email', 'max:255'],
            'phone'          => [
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') return;
                    if (strlen(preg_replace('/\D+/', '', (string) $value)) !== 10) {
                        $fail('Telefoonnummer moet uit precies 10 cijfers bestaan.');
                    }
                },
            ],
            'mobile'         => ['nullable', 'string', 'max:255'],
            'website'        => ['nullable', 'string', 'max:255'],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'address'        => ['nullable', 'string', 'max:255'],
            'postal_code'    => ['nullable', 'regex:/^\d{4}\s?[A-Za-z]{2}$/'],
            'city'           => ['nullable', 'string', 'max:255'],
            'country'        => ['nullable', 'string', 'max:255'],
            'iban'           => ['nullable', 'string', 'max:255'],
            'vat_number'     => ['nullable', 'string', 'max:255'],
            'kvk_number'     => ['nullable', 'string', 'max:255'],
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
        if (!empty($data['phone'])) {
            $data['phone'] = preg_replace('/\D+/', '', (string) $data['phone']);
        }
        if (!empty($data['postal_code'])) {
            $data['postal_code'] = strtoupper(preg_replace('/\s|-/', '', (string) $data['postal_code']));
        }
        return $data;
    }
}

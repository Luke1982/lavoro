<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CustomerStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = Auth::user();
        if (!$user) {
            return false;
        }
        return $user->isAdmin() || $user->hasPermission('customer.create');
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    if ($value === null || $value === '') {
                        return;
                    }
                    $digits = preg_replace('/\D+/', '', (string) $value);
                    if (strlen($digits) !== 10) {
                        $fail('Telefoonnummer moet uit precies 10 cijfers bestaan.');
                    }
                },
            ],
            'address' => ['nullable', 'string', 'max:255'],
            'postal_code' => [
                'nullable',
                'regex:/^\d{4}\s?[A-Za-z]{2}$/',
            ],
            'city' => ['nullable', 'string', 'max:255'],
            'country' => ['nullable', 'string', 'max:255'],
            'location_code' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Naam is verplicht.',
            'name.string' => 'Naam moet een tekst zijn.',
            'name.max' => 'Naam mag niet langer zijn dan :max tekens.',

            'email.email' => 'Het e-mailadres is ongeldig.',
            'email.max' => 'Het e-mailadres mag niet langer zijn dan :max tekens.',

            // Phone uses a closure for validation; message provided there in Dutch.

            'address.string' => 'Adres moet een tekst zijn.',
            'address.max' => 'Adres mag niet langer zijn dan :max tekens.',

            'postal_code.regex' => 'Postcode moet 4 cijfers gevolgd door 2 letters zijn (bijv. 1234AB of 1234 AB).',

            'city.string' => 'Plaats moet een tekst zijn.',
            'city.max' => 'Plaats mag niet langer zijn dan :max tekens.',

            'country.string' => 'Land moet een tekst zijn.',
            'country.max' => 'Land mag niet langer zijn dan :max tekens.',

            'location_code.string' => 'Locatiecode moet een tekst zijn.',
            'location_code.max' => 'Locatiecode mag niet langer zijn dan :max tekens.',
        ];
    }

    public function attributes(): array
    {
        return [
            'name' => 'naam',
            'email' => 'e-mailadres',
            'phone' => 'telefoonnummer',
            'address' => 'adres',
            'postal_code' => 'postcode',
            'city' => 'plaats',
            'country' => 'land',
            'location_code' => 'locatiecode',
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

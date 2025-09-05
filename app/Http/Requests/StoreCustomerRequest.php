<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
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

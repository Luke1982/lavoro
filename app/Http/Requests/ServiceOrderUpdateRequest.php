<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderUpdateRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'customer_id' => 'required|exists:customers,id',
            'description' => 'nullable|string|max:1000',
            'closed_on' => 'nullable|date',
            'signed_by' => 'nullable|nullable|string|max:100',
            'signature_base64' => 'nullable|string',
        ];
    }

    /**
     * Get the error messages for the defined validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'customer_id.required' => 'Klant is verplicht.',
            'customer_id.exists' => 'De opgegeven klant bestaat niet.',
            'description.max' => 'Beschrijving mag maximaal 1000 tekens bevatten.',
            'closed_on.date' => 'Gesloten op moet een geldige datum zijn.',
            'signed_by.max' => 'Ondertekend door mag maximaal 100 tekens bevatten.',
            'signature_base64.string' => 'Handtekening moet een geldige string zijn.',
        ];
    }
}

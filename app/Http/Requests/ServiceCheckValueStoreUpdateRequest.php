<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Class ServiceCheckValueStoreUpdateRequest
 *
 * This request handles the validation for storing and updating service check values.
 * @property string $service_check_id The value of the service check ID.
 * @method string merge() Merges.
 */
class ServiceCheckValueStoreUpdateRequest extends FormRequest
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
            'value' => 'required|string|max:255',
            'service_check_id' => 'required|exists:service_checks,id',
            'order' => 'nullable|integer|min:0',
        ];
    }

    public function messages(): array
    {
        return [
            'value.required' => 'De waarde is verplicht.',
        ];
    }
}

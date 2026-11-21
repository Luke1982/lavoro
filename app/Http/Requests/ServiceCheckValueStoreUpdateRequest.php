<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class ServiceCheckValueStoreUpdateRequest
 *
 * This request handles the validation for storing and updating service check values.
 *
 * @property string $service_check_id The value of the service check ID.
 *
 * @method string merge() Merges.
 */
class ServiceCheckValueStoreUpdateRequest extends FormRequest
{
    /**
     * The answers are part of a checkpoint's definition, so adding or changing one is
     * editing the checkpoint.
     */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('servicecheck.update');
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
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

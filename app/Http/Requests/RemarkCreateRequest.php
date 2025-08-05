<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Class RemarkCreateRequest
 *
 * @package App\Http\Requests
 * @method object has() Has.
 * @property string $content The content of the remark.
 * @property string $remarkable_type The type of the remarkable entity.
 * @property int $remarkable_id The ID of the remarkable entity.
 */
class RemarkCreateRequest extends FormRequest
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
            'content' => 'required|string|max:255',
            'remarkable_type' => 'required|string',
            'remarkable_id' => 'required|integer',
            'user_id' => 'required|integer|exists:users,id',
        ];
    }

    /**
     * Get the custom messages for the validation rules.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'content.required' => 'De opmerking is verplicht.',
            'remarkable_type.required' => 'Het type van de opmerking is verplicht.',
            'remarkable_id.required' => 'De ID van de opmerking is verplicht.',
            'user_id.required' => 'De gebruiker is verplicht.',
            'user_id.exists' => 'De opgegeven gebruiker bestaat niet.',
        ];
    }
}

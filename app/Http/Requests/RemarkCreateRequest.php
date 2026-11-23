<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\PlacesOnARecord;
use App\Models\Remark;
use App\Rules\AttachableType;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Class RemarkCreateRequest
 *
 * @method object has() Has.
 * @method \App\Models\User user(string $guard = null)
 *
 * @property string $content The content of the remark.
 * @property string $remarkable_type The type of the remarkable entity.
 * @property int $remarkable_id The ID of the remarkable entity.
 */
class RemarkCreateRequest extends FormRequest
{
    use PlacesOnARecord;

    /** The record class the request names, if remarks can be placed on it. */
    public function remarkableClass(): ?string
    {
        return Remark::attachableType($this->input('remarkable_type'));
    }

    /** Whoever may see a record may remark on it. */
    public function authorize(): bool
    {
        return $this->maySeeTheRecord(Remark::class);
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'content' => 'required|string|max:2000',
            'remarkable_type' => ['required', 'string', new AttachableType(Remark::ATTACHABLE_TO, 'opmerking')],
            'remarkable_id' => ['required', 'integer', function ($attribute, $value, $fail) {
                $record_class = $this->remarkableClass();

                if ($record_class !== null && !$record_class::whereKey($value)->exists()) {
                    $fail('Dit record bestaat niet (meer).');
                }
            }],
            'user_id' => 'required|integer|exists:users,id',
            'internal' => 'nullable|boolean',
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

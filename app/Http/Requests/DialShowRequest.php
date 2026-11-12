<?php

namespace App\Http\Requests;

use App\Support\PhoneNumber;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The number comes from a reminder's Bellen button; anyone signed in may have it
 * put on a page, because it was already on their lock screen.
 */
class DialShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'number' => ['required', 'string', 'regex:' . PhoneNumber::DIALABLE],
        ];
    }

    /**
     * Only a notification links here, so a number that does not pass was typed or
     * tampered with. There is no form to send it back to: going back would land
     * on whatever the browser fetched last, so it is simply not found.
     */
    protected function failedValidation(Validator $validator): void
    {
        abort(404);
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The customer asks for a change through the link they were sent. The link is
 * the right: the middleware let only its holder through, for this one quote.
 */
class QuoteResponseChangesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'message' => ['required', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Vul uw naam in.',
            'message.required' => 'Schrijf wat u anders zou willen.',
        ];
    }
}

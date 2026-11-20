<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The customer declines through the link they were sent, with their name and,
 * if they like, why: one of the reasons meant for them, and a few words. The
 * link is the right.
 */
class QuoteResponseDeclineRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'lost_reason_id' => [
                'nullable', 'integer',
                Rule::exists('quote_lost_reasons', 'id')->where('customer_can_pick', true),
            ],
            'message' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Vul uw naam in.'];
    }
}

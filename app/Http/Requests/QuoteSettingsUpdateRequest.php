<?php

namespace App\Http\Requests;

use App\Domain\Quotes\QuoteLayout;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

/** The settings that hold for every quote, each one saved on its own as it is changed. */
class QuoteSettingsUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', Quote::class);
    }

    public function rules(): array
    {
        return [
            ...QuoteLayout::rules('layout'),
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'max_upfront_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'hourly_rate' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:99999'],
            'reminder_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:90'],
            'expiry_notice_days' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:90'],
        ];
    }

    public function messages(): array
    {
        return [
            'max_upfront_percent.required' => 'Vul in hoeveel procent je een particulier vooraf mag vragen.',
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Domain\Quotes\QuoteLayout;
use App\Enums\QuoteTermsKinds;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'terms_kind' => ['sometimes', 'nullable', Rule::enum(QuoteTermsKinds::class)],
            'terms_text' => ['sometimes', 'nullable', 'string', 'max:200000'],
            'terms_url' => ['sometimes', 'nullable', 'url:http,https', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            'max_upfront_percent.required' => 'Vul in hoeveel procent je een particulier vooraf mag vragen.',
            'terms_url.url' => 'Vul een volledig webadres in, dat begint met https://.',
        ];
    }
}

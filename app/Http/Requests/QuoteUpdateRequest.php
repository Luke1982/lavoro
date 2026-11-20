<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesQuoteFields;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

class QuoteUpdateRequest extends FormRequest
{
    use ValidatesQuoteFields;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'));
    }

    public function rules(): array
    {
        /** @var Quote $quote */
        $quote = $this->route('quote');

        /** Each date is checked against the other one: as sent along, or as it is stored. */
        $quote_date = $this->input('quote_date', $quote->quote_date->toDateString());
        $kept_valid_until = $this->has('valid_until') ? null : $quote->valid_until?->toDateString();

        return [
            ...$this->quoteFieldRules($quote->customer_id),
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'customer_picks_options' => ['sometimes', 'boolean'],
            'quote_date' => [
                'sometimes', 'required', 'date',
                ...($kept_valid_until ? ['before_or_equal:' . $kept_valid_until] : []),
            ],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:' . $quote_date],
        ];
    }

    public function messages(): array
    {
        return $this->quoteFieldMessages();
    }
}

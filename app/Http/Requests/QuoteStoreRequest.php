<?php

namespace App\Http\Requests;

use App\Domain\Planning\Clock;
use App\Http\Requests\Concerns\ValidatesQuoteFields;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

class QuoteStoreRequest extends FormRequest
{
    use ValidatesQuoteFields;

    public function authorize(): bool
    {
        return $this->user()->can('create', Quote::class);
    }

    public function rules(): array
    {
        return [
            ...$this->quoteFieldRules($this->integer('customer_id') ?: null),
            'title' => ['required', 'string', 'max:255'],
            'customer_id' => ['required', 'exists:customers,id'],
            'quote_date' => ['sometimes', 'nullable', 'date'],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:' . ($this->input('quote_date') ?: Clock::today())],
        ];
    }

    public function messages(): array
    {
        return $this->quoteFieldMessages();
    }
}

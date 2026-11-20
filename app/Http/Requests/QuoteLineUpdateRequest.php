<?php

namespace App\Http\Requests;

use App\Domain\Quotes\VatRates;
use App\Enums\QuoteLineKinds;
use App\Http\Requests\Concerns\ValidatesQuoteLines;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteLineUpdateRequest extends FormRequest
{
    use ValidatesQuoteLines;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'));
    }

    public function rules(): array
    {
        return [
            'description' => ['sometimes', 'required', 'string', 'max:255'],
            'quantity' => [
                'sometimes', 'required', 'numeric', 'gte:0', 'max:99999',
                Rule::when($this->route('line')->kind === QuoteLineKinds::product->value, ['integer', 'min:1']),
            ],
            'unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'unit_price' => ['sometimes', 'required', 'numeric', 'min:-9999999', 'max:9999999'],
            'cost_price' => $this->costPriceRules(),
            'vat_percent' => ['sometimes', 'required', Rule::in(VatRates::ALL)],
            'discount_percent' => ['sometimes', 'required', 'numeric', 'min:0', 'max:100'],
            'is_optional' => ['sometimes', 'boolean'],
            'is_chosen' => ['sometimes', 'boolean'],
            'details' => ['sometimes', 'nullable', 'string', 'max:2000'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->costPriceMessages(),
            'quantity.integer' => 'Een product bied je per heel stuk aan.',
        ];
    }
}

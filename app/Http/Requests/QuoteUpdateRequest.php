<?php

namespace App\Http\Requests;

use App\Domain\Quotes\PaymentSchedule;
use App\Domain\Quotes\QuoteLayout;
use App\Domain\Quotes\VatRates;
use App\Enums\QuotePricingMethods;
use App\Http\Requests\Concerns\ValidatesQuoteFields;
use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'default_vat_percent' => ['sometimes', 'required', Rule::in(VatRates::ALL)],
            'quote_date' => [
                'sometimes', 'required', 'date',
                ...($kept_valid_until ? ['before_or_equal:' . $kept_valid_until] : []),
            ],
            'valid_until' => ['sometimes', 'nullable', 'date', 'after_or_equal:' . $quote_date],
            'is_consumer' => ['sometimes', 'boolean'],
            ...QuoteLayout::rules('layout'),
            'pricing_method' => ['sometimes', 'required', Rule::enum(QuotePricingMethods::class)],
            'start_period' => ['sometimes', 'nullable', 'string', 'max:255'],
            'duration' => ['sometimes', 'nullable', 'string', 'max:255'],
            'payment_terms' => ['sometimes', 'nullable', 'string', 'max:2000'],
            ...PaymentSchedule::rules(),
        ];
    }

    /**
     * The payment terms add up to the whole and ask a private customer no more
     * up front than allowed: checked against what is sent along, and otherwise
     * against what the quote already says. Only terms that passed the rules are
     * added up.
     */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty() || (!$this->has('payment_schedule') && !$this->has('is_consumer'))) {
                return;
            }

            /** @var Quote $quote */
            $quote = $this->route('quote');
            $problem = PaymentSchedule::problem(
                $this->has('payment_schedule') ? $this->input('payment_schedule') : $quote->payment_schedule,
                $this->has('is_consumer') ? $this->boolean('is_consumer') : $quote->is_consumer,
            );

            if ($problem !== null) {
                $field = $this->has('payment_schedule') ? 'payment_schedule' : 'is_consumer';
                $validator->errors()->add($field, $problem);
            }
        }];
    }

    public function messages(): array
    {
        return $this->quoteFieldMessages();
    }
}

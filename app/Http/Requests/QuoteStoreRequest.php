<?php

namespace App\Http\Requests;

use App\Domain\Planning\Clock;
use App\Domain\Quotes\QuoteOrigins;
use App\Http\Requests\Concerns\ValidatesQuoteFields;
use App\Models\Quote;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class QuoteStoreRequest extends FormRequest
{
    use ValidatesQuoteFields;

    private ServiceOrder|Ticket|null $origin = null;

    public function authorize(): bool
    {
        $origin = $this->origin();

        return $this->user()->can('create', Quote::class)
            && ($origin === null || QuoteOrigins::mayOpen($this->user(), $origin));
    }

    public function rules(): array
    {
        return [
            ...$this->quoteFieldRules($this->integer('customer_id') ?: null),
            'title' => ['required', 'string', 'max:255'],
            'customer_id' => ['required', 'exists:customers,id'],
            'quote_date' => ['sometimes', 'nullable', 'date'],
            'valid_until' => [
                'sometimes', 'nullable', 'date',
                'after_or_equal:' . ($this->input('quote_date') ?: Clock::today()),
            ],
            'template_id' => ['sometimes', 'nullable', 'exists:quote_templates,id', 'prohibits:origin_type'],
            'origin_type' => ['sometimes', 'nullable', Rule::in(array_keys(QuoteOrigins::KINDS))],
            'origin_id' => ['required_with:origin_type', 'integer'],
            'origin_lines' => ['sometimes', 'array'],
            'origin_lines.*' => ['string', 'max:50'],
        ];
    }

    /** The werkbon or storing it is made from, when it is. */
    public function origin(): ServiceOrder|Ticket|null
    {
        $kind = $this->input('origin_type');

        if (!is_string($kind) || !isset(QuoteOrigins::KINDS[$kind])) {
            return null;
        }

        return $this->origin ??= QuoteOrigins::find($kind, $this->integer('origin_id'));
    }

    public function messages(): array
    {
        return $this->quoteFieldMessages();
    }
}

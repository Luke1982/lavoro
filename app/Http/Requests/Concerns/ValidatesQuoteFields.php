<?php

namespace App\Http\Requests\Concerns;

use App\Rules\ContactOfCustomer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The fields a quote is made and edited with. A location and a contact person
 * come from the quote's own customer.
 *
 * @mixin FormRequest
 */
trait ValidatesQuoteFields
{
    /** @return array<string, array<int, mixed>> */
    protected function quoteFieldRules(?int $customer_id): array
    {
        return [
            'location_id' => [
                'sometimes', 'nullable',
                Rule::exists('locations', 'id')->where('customer_id', $customer_id),
            ],
            'contact_id' => ['sometimes', 'nullable', new ContactOfCustomer($customer_id, 'deze offerte')],
            'project_id' => [
                'sometimes', 'nullable',
                Rule::exists('projects', 'id')->where('customer_id', $customer_id),
            ],
            'intro_text' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'closing_text' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ];
    }

    /** @return array<string, string> */
    protected function quoteFieldMessages(): array
    {
        return [
            'location_id.exists' => 'Deze locatie hoort niet bij de klant van deze offerte.',
            'project_id.exists' => 'Dit project hoort niet bij de klant van deze offerte.',
            'valid_until.after_or_equal' => 'Geldig tot ligt vóór de offertedatum.',
            'quote_date.before_or_equal' => 'De offertedatum ligt na geldig tot.',
        ];
    }
}

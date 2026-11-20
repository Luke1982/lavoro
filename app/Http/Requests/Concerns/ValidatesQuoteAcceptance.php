<?php

namespace App\Http\Requests\Concerns;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saying yes to a quote, the same on the customer's page as in the app: a name,
 * a signature drawn on the screen, and which of the quote's own optional lines
 * are taken.
 *
 * @mixin FormRequest
 */
trait ValidatesQuoteAcceptance
{
    /** @return array<string, array<int, mixed>> */
    protected function acceptanceRules(Quote $quote): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'signature' => ['required', 'string', 'max:2000000', 'regex:/^data:image\/(png|jpeg);base64,/'],
            'options' => ['sometimes', 'array'],
            'options.*' => [
                'integer',
                Rule::exists('quote_lines', 'id')->where('quote_id', $quote->id)->where('is_optional', true),
            ],
        ];
    }

    /** @return array<string, string> */
    protected function acceptanceMessages(): array
    {
        return [
            'name.required' => 'Vul uw naam in.',
            'signature.required' => 'Zet uw handtekening.',
            'signature.regex' => 'De handtekening kon niet gelezen worden. Zet hem opnieuw.',
            'options.*.exists' => 'Deze optie hoort niet bij deze offerte.',
        ];
    }
}

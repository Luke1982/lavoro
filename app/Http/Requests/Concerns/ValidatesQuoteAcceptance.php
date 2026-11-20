<?php

namespace App\Http\Requests\Concerns;

use App\Models\Quote;
use App\Models\QuoteVersion;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Saying yes to a quote, the same on the customer's page as in the app: a name,
 * a signature drawn on the screen, which of the quote's own options are taken
 * and which one of each set of alternatives. When the version comes with
 * general terms, they have to be accepted with it: the law wants the customer
 * to have had them, and the tick is the proof. A private customer may ask for
 * the work to start within the 14 days they have to change their mind.
 *
 * @mixin FormRequest
 */
trait ValidatesQuoteAcceptance
{
    /**
     * @param  QuoteVersion|null  $send  the send the link showed; in the app, the one on offer
     * @return array<string, array<int, mixed>>
     */
    protected function acceptanceRules(Quote $quote, ?QuoteVersion $send = null): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'signature' => ['required', 'string', 'max:2000000', 'regex:/^data:image\/(png|jpeg);base64,/'],
            'options' => ['sometimes', 'array', $this->oneAlternativeEach($quote)],
            'options.*' => [
                'integer',
                Rule::exists('quote_lines', 'id')
                    ->where('quote_id', $quote->id)
                    ->where(fn ($query) => $query->where('is_optional', true)->orWhereNotNull('choice_group')),
            ],
            'terms_accepted' => $quote->termsOnOffer($send) ? ['accepted'] : ['sometimes', 'boolean'],
            'starts_within_cooling_off' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    protected function acceptanceMessages(): array
    {
        return [
            'name.required' => 'Vul uw naam in.',
            'signature.required' => 'Zet uw handtekening.',
            'signature.regex' => 'De handtekening kon niet gelezen worden. Zet hem opnieuw.',
            'options.*.exists' => 'Deze keuze hoort niet bij deze offerte.',
            'terms_accepted.accepted' => 'Ga akkoord met de algemene voorwaarden.',
        ];
    }

    private function oneAlternativeEach(Quote $quote): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($quote) {
            $groups = $quote->lines()->whereIn('id', (array) $value)->whereNotNull('choice_group')
                ->pluck('choice_group');

            if ($groups->count() !== $groups->unique()->count()) {
                $fail('Kies van elk alternatief er één.');
            }
        };
    }
}

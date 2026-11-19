<?php

namespace App\Http\Requests\Concerns;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A purchase price on a quote line is a margin: only who may see margins may
 * fill one in, on a new line as on an existing one.
 *
 * @mixin FormRequest
 */
trait ValidatesQuoteLines
{
    /** @return array<int, mixed> */
    protected function costPriceRules(): array
    {
        return [
            'sometimes', 'nullable', 'numeric', 'min:0', 'max:9999999',
            Rule::prohibitedIf(!$this->user()->can('seeMargins', Quote::class)),
        ];
    }

    /** @return array<string, string> */
    protected function costPriceMessages(): array
    {
        return ['cost_price.prohibited' => 'Je mag geen inkoopprijzen op offertes zien of invullen.'];
    }
}

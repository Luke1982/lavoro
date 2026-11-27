<?php

namespace App\Http\Requests;

use App\Models\Quote;

/**
 * A project from an accepted quote: the fields of any new project, for the
 * quote's own customer, which is read from the quote and never from the screen.
 *
 * @method Quote route(string $key = null)
 */
class QuoteProjectStoreRequest extends ProjectStoreRequest
{
    public function authorize(): bool
    {
        return parent::authorize() && $this->user()->can('update', $this->route('quote'));
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['customer_id' => $this->route('quote')->customer_id]);
    }
}

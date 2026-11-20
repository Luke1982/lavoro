<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Making a line an alternative to the one above it, or taking it out of its alternatives. */
class QuoteLineAlternativeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'));
    }

    public function rules(): array
    {
        return [];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Searching what can be offered. Whoever may work on a quote may see sale
 * prices of the catalogue, also without product.read: offering is selling.
 */
class QuoteCatalogSearchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'));
    }

    public function rules(): array
    {
        return ['q' => ['nullable', 'string', 'max:255']];
    }
}

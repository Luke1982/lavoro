<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

/** A copy of a quote as a new concept, for the same customer or another one. */
class QuoteDuplicateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('quote')) && $this->user()->can('create', Quote::class);
    }

    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'customer_id' => ['required', 'exists:customers,id'],
        ];
    }
}

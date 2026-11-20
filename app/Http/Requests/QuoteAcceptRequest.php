<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesQuoteAcceptance;
use App\Models\ServiceOrder;
use Illuminate\Foundation\Http\FormRequest;

/** The customer accepts in the app, signing on the screen in front of whoever made the quote. */
class QuoteAcceptRequest extends FormRequest
{
    use ValidatesQuoteAcceptance;

    /** Making the werkbon in the same step asks the right to make one. */
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'))
            && (!$this->boolean('make_service_order') || $this->user()->can('create', ServiceOrder::class));
    }

    public function rules(): array
    {
        return [
            ...$this->acceptanceRules($this->route('quote')),
            'make_service_order' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return $this->acceptanceMessages();
    }
}

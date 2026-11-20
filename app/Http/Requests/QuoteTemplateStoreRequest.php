<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

/** Keeping a quote as a template, for whoever may make quotes. */
class QuoteTemplateStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('quote')) && $this->user()->can('create', Quote::class);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return ['name.required' => 'Geef het sjabloon een naam.'];
    }
}

<?php

namespace App\Http\Requests;

use App\Domain\Quotes\VatRates;
use App\Enums\QuoteLineKinds;
use App\Http\Requests\Concerns\ValidatesQuoteLines;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** A material, a line of its own, or a text. A product has its own request. */
class QuoteLineStoreRequest extends FormRequest
{
    use ValidatesQuoteLines;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('quote'));
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(QuoteLineKinds::class)->except(QuoteLineKinds::product)],
            'material_id' => ['required_if:kind,material', 'nullable', 'integer', 'exists:materials,id'],
            'description' => ['required_unless:kind,material', 'nullable', 'string', 'max:255'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'gte:0', 'max:99999'],
            'unit' => ['sometimes', 'nullable', 'string', 'max:30'],
            'unit_price' => ['sometimes', 'nullable', 'numeric', 'min:-9999999', 'max:9999999'],
            'cost_price' => $this->costPriceRules(),
            'vat_percent' => ['sometimes', 'nullable', Rule::in(VatRates::ALL)],
            'details' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'is_optional' => ['sometimes', 'boolean'],
            'parent_id' => [
                'sometimes',
                'nullable',
                'prohibited_if:kind,' . QuoteLineKinds::text->value,
                'prohibits:is_optional',
                Rule::exists('quote_lines', 'id')
                    ->where('quote_id', $this->route('quote')->id)
                    ->where('kind', QuoteLineKinds::product->value)
                    ->whereNull('parent_id'),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->costPriceMessages(),
            'material_id.required_if' => 'Kies een materiaal.',
            'description.required_unless' => 'Vul een omschrijving in.',
            'parent_id.exists' => 'Een materiaal of eigen regel hangt alleen onder een product van deze offerte.',
            'parent_id.prohibited_if' => 'Een tekst hangt niet onder een product.',
        ];
    }
}

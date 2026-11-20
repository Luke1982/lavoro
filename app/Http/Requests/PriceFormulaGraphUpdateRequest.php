<?php

namespace App\Http\Requests;

use App\Rules\PriceFormulaGraph;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving the graph from the editor, with the version number it was opened on,
 * so a save made in the meantime by somebody else is never overwritten unseen.
 */
class PriceFormulaGraphUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('priceformula'));
    }

    public function rules(): array
    {
        return [
            'graph' => ['required', 'array', new PriceFormulaGraph],
            'version' => ['required', 'integer'],
        ];
    }
}

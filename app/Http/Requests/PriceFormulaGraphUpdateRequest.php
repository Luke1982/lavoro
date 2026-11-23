<?php

namespace App\Http\Requests;

use App\Rules\PriceFormulaGraph;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Saving the graph from the editor, with the version number it was opened on,
 * so a save made in the meantime by somebody else is never overwritten unseen.
 * Taking the running contracts along to a new version reprices them, which
 * takes the right to move contracts on as well.
 */
class PriceFormulaGraphUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        $formula = $this->route('priceformula');

        return $this->user()->can('update', $formula)
            && (!$this->boolean('move_contracts') || $this->user()->can('upgradeContracts', $formula));
    }

    public function rules(): array
    {
        return [
            'graph' => ['required', 'array', new PriceFormulaGraph],
            'version' => ['required', 'integer'],
            'version_name' => ['nullable', 'string', 'max:60'],
            'move_contracts' => ['nullable', 'boolean'],
        ];
    }
}

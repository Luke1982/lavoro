<?php

namespace App\Http\Requests;

use App\Enums\CatalogImportKind;
use App\Http\Requests\Concerns\ValidatesImportSettings;
use App\Models\ImportMapping;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Saves the choices on the mapping screen under a name. A name that already
 * exists for this kind is overwritten: that is how a mapping is corrected.
 */
class ImportMappingStoreRequest extends FormRequest
{
    use ValidatesImportSettings;

    public function authorize(): bool
    {
        $kind = $this->importKind();

        return $kind !== null && $this->user()->can('create', [ImportMapping::class, $kind]);
    }

    public function importKind(): ?CatalogImportKind
    {
        return CatalogImportKind::tryFrom((string) $this->input('kind'));
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', Rule::enum(CatalogImportKind::class)],
            'name' => ['required', 'string', 'max:100'],
            ...$this->settingsRules(),
        ];
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkSettings($validator),
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Geef de koppeling een naam, bijvoorbeeld die van de leverancier.',
        ];
    }
}

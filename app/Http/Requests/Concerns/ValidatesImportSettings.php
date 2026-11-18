<?php

namespace App\Http\Requests\Concerns;

use App\Domain\Imports\CatalogTarget;
use App\Domain\Imports\ImportSettings;
use App\Enums\CatalogImportKind;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * The column choices of a spreadsheet import, as the mapping screen sends them
 * both to run an import and to save them under a name. Only the fields this
 * person can see may be mapped: a price field someone may not read is not one
 * they get to fill either.
 */
trait ValidatesImportSettings
{
    abstract public function importKind(): ?CatalogImportKind;

    protected function settingsRules(): array
    {
        $kind = $this->importKind();

        if (!$kind) {
            return [];
        }

        $target = $kind->target();
        $fields = $target->fields($this->user());
        $lookups = $target->lookupFields($this->user());

        $rules = [
            'sheet' => ['nullable', 'string', 'max:255'],
            'header_row' => ['required', 'integer', 'min:1', 'max:1000'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'markup_percent' => ['nullable', 'numeric', 'min:0', 'max:1000'],
            'columns' => ['nullable', 'array:' . implode(',', array_keys($fields))],
            'columns.*' => ['nullable', 'string', 'max:255'],
            'update' => ['nullable', 'array:' . implode(',', array_keys($fields))],
            'update.*' => ['boolean'],
            'fixed' => ['nullable', 'array:' . implode(',', array_keys($lookups))],
            'match_keys' => ['nullable', 'array'],
            'match_keys.*' => ['string', Rule::in($target->matchKeyNames())],
        ];

        foreach ($lookups as $key => $field) {
            $rules['fixed.' . $key] = ['nullable', 'integer', Rule::exists((new $field['lookup'])->getTable(), 'id')];
        }

        return $rules;
    }

    /**
     * The checks that span fields: things that cannot work together, said where
     * whoever maps can fix them.
     */
    protected function checkSettings(Validator $validator): void
    {
        $kind = $this->importKind();

        if (!$kind || $validator->errors()->isNotEmpty()) {
            return;
        }

        $target = $kind->target();
        $settings = $this->settings();
        $columns = $settings['columns'];

        if ($columns === []) {
            $validator->errors()->add('columns', 'Koppel minstens één kolom.');

            return;
        }

        if (isset($columns['article_number']) && !$settings['supplier_id']) {
            $validator->errors()->add('supplier_id', 'Kies de leverancier waar dit artikelnummer bij hoort.');
        }

        if ($settings['markup_percent'] !== null && !isset($columns[$target->costField()])) {
            $validator->errors()->add(
                'markup_percent',
                'De opslag wordt berekend over de ' . $this->costLabel($target) . ': koppel eerst die kolom.',
            );
        }

        if (!isset($columns[$target->primaryField()])) {
            return;
        }

        foreach ($target->lookupFields($this->user()) as $key => $field) {
            if (($field['required'] ?? false) && !isset($columns[$key]) && !isset($settings['fixed'][$key])) {
                $validator->errors()->add(
                    'fixed.' . $key,
                    'Nieuwe ' . $target->plural() . ' hebben een ' . mb_strtolower($field['label'])
                        . ' nodig: kies een kolom of een vaste waarde.',
                );
            }
        }
    }

    /** The validated choices in the shape the import reads them. */
    public function settings(): array
    {
        return ImportSettings::normalize($this->only([
            'sheet',
            'header_row',
            'supplier_id',
            'markup_percent',
            'columns',
            'fixed',
            'update',
            'match_keys',
        ]));
    }

    private function costLabel(CatalogTarget $target): string
    {
        return mb_strtolower($target->fields($this->user())[$target->costField()]['label'] ?? 'kostprijs');
    }
}

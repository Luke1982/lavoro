<?php

namespace App\Http\Requests;

use App\Domain\Imports\ImportSheet;
use App\Enums\CatalogImportKind;
use App\Http\Requests\Concerns\ValidatesImportSettings;
use App\Models\CatalogImport;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CatalogImportMapRequest extends FormRequest
{
    use ValidatesImportSettings;

    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('catalog_import'));
    }

    public function importKind(): ?CatalogImportKind
    {
        return $this->catalogImport()->kind;
    }

    public function rules(): array
    {
        return $this->settingsRules();
    }

    public function after(): array
    {
        return [
            fn (Validator $validator) => $this->checkSettings($validator),
            fn (Validator $validator) => $this->checkAgainstSheet($validator),
        ];
    }

    /**
     * A saved mapping can name a column this month's file no longer has, or a tab
     * that is not in it: said per field, so it can be pointed elsewhere.
     */
    private function checkAgainstSheet(Validator $validator): void
    {
        if ($validator->errors()->isNotEmpty()) {
            return;
        }

        $settings = $this->settings();
        $sheet = ImportSheet::open($this->catalogImport()->filePath(), $settings['sheet']);

        if ($settings['sheet'] !== null && !in_array($settings['sheet'], $sheet->sheetNames(), true)) {
            $validator->errors()->add('sheet', 'Het tabblad ‘' . $settings['sheet'] . '’ staat niet in dit bestand.');

            return;
        }

        $names = array_column($sheet->columns($settings['header_row']), 'name');

        foreach ($settings['columns'] as $field => $column) {
            if (!in_array($column, $names, true)) {
                $validator->errors()->add('columns.' . $field, 'De kolom ‘' . $column . '’ staat niet in dit bestand.');
            }
        }
    }

    private function catalogImport(): CatalogImport
    {
        return $this->route('catalog_import');
    }
}

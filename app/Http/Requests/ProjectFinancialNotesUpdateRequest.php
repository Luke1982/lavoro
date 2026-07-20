<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectFinancialNotesUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageFinancials', $this->route('project'));
    }

    /**
     * Earlier versions stored a bare 2D array. Normalising it here keeps a
     * single shape in the column and stops such a payload validating to null.
     */
    protected function prepareForValidation(): void
    {
        $financial_notes = $this->input('financial_notes');

        if (is_array($financial_notes) && $financial_notes !== [] && array_is_list($financial_notes)) {
            $this->merge([
                'financial_notes' => [
                    'data' => $financial_notes,
                    'style' => [],
                    'mergeCells' => [],
                    'columns' => [],
                ],
            ]);
        }
    }

    /**
     * The whole snapshot is required whenever one is sent, because the column
     * is replaced wholesale; a payload missing a key would silently drop the
     * grid, its styling or its merges. "present" rather than "required" so an
     * unstyled sheet may legitimately send empty maps.
     */
    public function rules(): array
    {
        $rules = [
            'financial_notes' => ['present', 'nullable', 'array'],
            'financial_notes.data.*' => ['array', 'max:100'],
            'financial_notes.data.*.*' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    if (!is_scalar($value)) {
                        $fail('Ongeldige celwaarde in de administratie.');
                    }
                },
            ],
            'financial_notes.style.*' => ['nullable', 'string', 'max:500'],
            'financial_notes.mergeCells.*' => ['array', 'size:2'],
            'financial_notes.mergeCells.*.*' => ['integer', 'min:1', 'max:2000'],
            'financial_notes.columns.*' => ['array'],
            'financial_notes.columns.*.width' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'financial_notes.columns.*.title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ];

        $financial_notes = $this->input('financial_notes');

        if (is_array($financial_notes) && $financial_notes !== []) {
            $rules['financial_notes.data'] = ['present', 'array', 'max:2000'];
            $rules['financial_notes.style'] = ['present', 'array', 'max:200000'];
            $rules['financial_notes.mergeCells'] = ['present', 'array', 'max:5000'];
            $rules['financial_notes.columns'] = ['present', 'array', 'max:100'];
        }

        return $rules;
    }
}

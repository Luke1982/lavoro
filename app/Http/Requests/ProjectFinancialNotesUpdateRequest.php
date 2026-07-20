<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectFinancialNotesUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageFinancials', $this->route('project'));
    }

    public function rules(): array
    {
        return [
            'financial_notes' => ['present', 'nullable', 'array', 'max:2000'],
            'financial_notes.*' => ['array', 'max:100'],
            'financial_notes.*.*' => [
                'nullable',
                function ($attribute, $value, $fail) {
                    if (!is_scalar($value)) {
                        $fail('Ongeldige celwaarde in de administratie.');
                    }
                },
            ],
        ];
    }
}

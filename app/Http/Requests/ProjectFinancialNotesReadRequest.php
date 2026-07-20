<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ProjectFinancialNotesReadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageFinancials', $this->route('project'));
    }

    public function rules(): array
    {
        return [];
    }
}

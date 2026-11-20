<?php

namespace App\Http\Requests;

use App\Models\Quote;
use Illuminate\Foundation\Http\FormRequest;

/** The general terms every quote goes out with, as one PDF. */
class QuoteTermsStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manageSettings', Quote::class);
    }

    public function rules(): array
    {
        return [
            'terms' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }

    public function messages(): array
    {
        return [
            'terms.mimes' => 'Upload de algemene voorwaarden als PDF.',
            'terms.max' => 'Het bestand mag niet groter zijn dan 10 MB.',
        ];
    }
}

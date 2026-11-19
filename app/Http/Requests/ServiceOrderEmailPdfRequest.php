<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user()
 */
class ServiceOrderEmailPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('emailPdf', $this->route('serviceorder'));
    }

    public function rules(): array
    {
        return [];
    }
}

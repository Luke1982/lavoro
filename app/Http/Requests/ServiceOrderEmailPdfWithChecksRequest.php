<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user()
 */
class ServiceOrderEmailPdfWithChecksRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('emailPdfWithChecks', $this->route('serviceorder'));
    }

    public function rules(): array
    {
        return [];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The keuring as a PDF, for whoever may export or mail it.
 *
 * @method \App\Models\User user($guard = null)
 */
class ServiceJobExportPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('exportPdf', $this->route('servicejob'));
    }

    public function rules(): array
    {
        return [];
    }
}

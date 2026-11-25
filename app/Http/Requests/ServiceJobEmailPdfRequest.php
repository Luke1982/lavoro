<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Mailing the keuring's PDF to the customer.
 *
 * @method \App\Models\User user($guard = null)
 */
class ServiceJobEmailPdfRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('mailPdf', $this->route('servicejob'));
    }

    public function rules(): array
    {
        return [];
    }
}

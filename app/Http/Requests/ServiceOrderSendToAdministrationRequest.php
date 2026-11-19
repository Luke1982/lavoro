<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user()
 */
class ServiceOrderSendToAdministrationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sendToAdministration', $this->route('serviceorder'));
    }

    public function rules(): array
    {
        return [];
    }
}

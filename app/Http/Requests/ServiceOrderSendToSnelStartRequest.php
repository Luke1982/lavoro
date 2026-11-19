<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user()
 */
class ServiceOrderSendToSnelStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('sendToSnelStart', $this->route('serviceorder'));
    }

    public function rules(): array
    {
        return [];
    }
}

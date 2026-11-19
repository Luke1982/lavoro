<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * @method \App\Models\User user()
 */
class ServiceOrderEventWidgetRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('service_order'));
    }

    public function rules(): array
    {
        return [];
    }
}

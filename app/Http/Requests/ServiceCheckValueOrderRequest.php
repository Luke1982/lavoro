<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceCheckValueOrderRequest extends FormRequest
{
    /** The answers are part of a checkpoint's definition, so ordering them is editing the checkpoint. */
    public function authorize(): bool
    {
        return $this->user()->hasPermission('servicecheck.update');
    }

    public function rules(): array
    {
        return [
            'payload' => ['required', 'array'],
            'payload.*.id' => ['required', 'integer', 'distinct', 'exists:service_check_values,id'],
            'payload.*.order' => ['required', 'integer', 'min:0'],
        ];
    }
}

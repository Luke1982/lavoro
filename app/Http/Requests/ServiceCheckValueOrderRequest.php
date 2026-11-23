<?php

namespace App\Http\Requests;

use App\Models\ServiceCheck;
use Illuminate\Foundation\Http\FormRequest;

class ServiceCheckValueOrderRequest extends FormRequest
{
    /** The answers are part of a checkpoint's definition, so ordering them is editing the checkpoint. */
    public function authorize(): bool
    {
        return $this->user()->can('update', ServiceCheck::class);
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

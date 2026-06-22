<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class EventExecutionUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('executeOwn', $this->route('event'));
    }

    public function rules(): array
    {
        return [
            'actual_start' => ['required', 'date'],
            'actual_end' => ['required', 'date', 'after:actual_start'],
            'signature_base64' => ['required', 'string'],
        ];
    }
}

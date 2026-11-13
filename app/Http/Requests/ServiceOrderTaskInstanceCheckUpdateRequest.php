<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ServiceOrderTaskInstanceCheckUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('tick', $this->route('serviceordertaskinstancecheck'));
    }

    public function rules(): array
    {
        return [
            'is_done' => ['required', 'boolean'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;

class ServiceOrderTaskInstanceToggleRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('toggle', $this->route('serviceordertaskinstance'));
    }

    public function rules(): array
    {
        return [
            'is_complete' => ['required', 'boolean'],
        ];
    }
}

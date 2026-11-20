<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

/** Starting or finishing the login at another system: the same right as setting up a connection. */
class IntegrationOAuthRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('integration.manage') ?? false;
    }

    public function rules(): array
    {
        return [
            'code' => ['nullable', 'string', 'max:4096'],
            'state' => ['nullable', 'string', 'max:255'],
            'error' => ['nullable', 'string', 'max:255'],
            'error_description' => ['nullable', 'string', 'max:2000'],
        ];
    }
}

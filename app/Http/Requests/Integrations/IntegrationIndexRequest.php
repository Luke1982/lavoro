<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class IntegrationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('integration.manage') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}

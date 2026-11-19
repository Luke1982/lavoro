<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

/** Testing or removing a connection, or forgetting one of its keys: nothing to validate. */
class IntegrationManageRequest extends FormRequest
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

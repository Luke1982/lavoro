<?php

namespace App\Http\Requests\Integrations;

use Illuminate\Foundation\Http\FormRequest;

class IntegrationPullRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyPermission(['integration.pull', 'integration.manage']) ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}

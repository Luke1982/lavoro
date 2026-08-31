<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ForgetIntegrationSecretRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('technical.management') ?? false;
    }

    public function rules(): array
    {
        return [];
    }
}

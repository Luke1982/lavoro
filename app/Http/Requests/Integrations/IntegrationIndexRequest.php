<?php

namespace App\Http\Requests\Integrations;

use App\Models\IntegrationConnection;
use Illuminate\Foundation\Http\FormRequest;

class IntegrationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('viewAny', IntegrationConnection::class);
    }

    public function rules(): array
    {
        return [];
    }
}

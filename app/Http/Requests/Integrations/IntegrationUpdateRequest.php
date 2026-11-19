<?php

namespace App\Http\Requests\Integrations;

use App\Models\IntegrationConnection;
use Illuminate\Foundation\Http\FormRequest;

class IntegrationUpdateRequest extends FormRequest
{
    use ValidatesConnectionSettings;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('integration.manage') ?? false;
    }

    public function rules(): array
    {
        return ['is_active' => ['required', 'boolean']] + $this->settingRules($this->connection()->connectorClass(), $this->connection());
    }

    public function attributes(): array
    {
        return ['is_active' => 'actief'] + $this->settingAttributes($this->connection()->connectorClass());
    }

    public function messages(): array
    {
        return $this->settingMessages($this->connection()->connectorClass());
    }

    private function connection(): IntegrationConnection
    {
        return $this->route('integration');
    }
}

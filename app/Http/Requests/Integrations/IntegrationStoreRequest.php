<?php

namespace App\Http\Requests\Integrations;

use App\Domain\Integrations\Connector;
use App\Domain\Integrations\Connectors;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class IntegrationStoreRequest extends FormRequest
{
    use ValidatesConnectionSettings;

    public function authorize(): bool
    {
        return $this->user()?->hasPermission('integration.manage') ?? false;
    }

    public function rules(): array
    {
        $rules = [
            'connector' => [
                'required',
                'string',
                Rule::in(array_keys(Connectors::all())),
                Rule::unique('integration_connections', 'connector'),
            ],
        ];

        return $this->connector() ? $rules + $this->settingRules($this->connector()) : $rules;
    }

    public function attributes(): array
    {
        return ['connector' => 'systeem'] + ($this->connector() ? $this->settingAttributes($this->connector()) : []);
    }

    public function messages(): array
    {
        return ['connector.unique' => 'Dit systeem is al gekoppeld.']
            + ($this->connector() ? $this->settingMessages($this->connector()) : []);
    }

    /** @return class-string<Connector>|null The connector asked for, when there is one by that key. */
    private function connector(): ?string
    {
        $key = (string) $this->input('connector');

        return Connectors::exists($key) ? Connectors::classFor($key) : null;
    }
}

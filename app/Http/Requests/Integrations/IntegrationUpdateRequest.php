<?php

namespace App\Http\Requests\Integrations;

use App\Models\IntegrationConnection;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

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

    /**
     * Every link is an id in the administration, division or company that is
     * chosen; in another one those ids mean other records, or nothing. So that
     * choice stays while anything is linked: another one is a new connection.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $connection = $this->connection();

            foreach ($connection->connectorClass()::optionFields() as $field) {
                $wanted = $this->input('options.' . $field->key);
                $current = $connection->option($field->key);

                if ($field->scope && filled($wanted) && filled($current) && (string) $wanted !== (string) $current && $connection->links()->exists()) {
                    $validator->errors()->add('options.' . $field->key, 'Dit kan niet meer veranderen zolang er klanten of materialen gekoppeld zijn. '
                        . 'Verwijder de koppeling en koppel opnieuw om een andere te gebruiken.');
                }
            }
        });
    }

    private function connection(): IntegrationConnection
    {
        return $this->route('integration');
    }
}

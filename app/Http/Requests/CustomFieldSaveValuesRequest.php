<?php

namespace App\Http\Requests;

use App\Models\CustomField;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;

class CustomFieldSaveValuesRequest extends FormRequest
{
    private static array $model_map = [
        'customer' => \App\Models\Customer::class,
        'asset' => \App\Models\Asset::class,
        'product' => \App\Models\Product::class,
        'service_order' => \App\Models\ServiceOrder::class,
        'ticket' => \App\Models\Ticket::class,
    ];

    public function authorize(): bool
    {
        $user = Auth::user();
        return $user && ($user->isAdmin() || $user->hasPermission('customfield.update'));
    }

    public function rules(): array
    {
        $rules = [
            'model_type' => ['required', 'string', 'in:' . implode(',', array_keys(self::$model_map))],
            'model_id' => ['required', 'integer'],
            'values' => ['required', 'array'],
        ];

        $custom_fields = $this->resolveCustomFields();

        foreach ($this->input('values', []) as $field_id => $value) {
            $field = $custom_fields->get($field_id);
            $field_rules = ['nullable'];

            if ($field) {
                if ($field->field_type === 'number') {
                    $field_rules[] = 'numeric';
                }
                if ($field->field_type === 'date') {
                    $field_rules[] = 'date';
                }
                if ($field->required) {
                    $field_rules = array_filter($field_rules, fn ($r) => $r !== 'nullable');
                    array_unshift($field_rules, 'required');
                }
            }

            $rules["values.{$field_id}"] = $field_rules;
        }

        return $rules;
    }

    public function messages(): array
    {
        $messages = [];
        $custom_fields = $this->resolveCustomFields();

        foreach ($custom_fields as $field_id => $field) {
            $messages["values.{$field_id}.numeric"] = "{$field->name} moet een getal zijn.";
            $messages["values.{$field_id}.date"] = "{$field->name} moet een geldige datum zijn.";
            $messages["values.{$field_id}.required"] = "{$field->name} is verplicht.";
        }

        return $messages;
    }

    public function modelClass(): string
    {
        return self::$model_map[$this->input('model_type')];
    }

    public function resolveCustomFields()
    {
        return CustomField::whereIn('id', array_keys($this->input('values', [])))->get()->keyBy('id');
    }
}

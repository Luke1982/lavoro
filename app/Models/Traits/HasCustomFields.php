<?php

namespace App\Models\Traits;

use App\Models\CustomField;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

trait HasCustomFields
{
    public function customFields(): MorphToMany
    {
        return $this->morphToMany(CustomField::class, 'customfieldable')
            ->withPivot('value')
            ->withTimestamps();
    }

    public function allCustomFieldsWithValues(): array
    {
        $model_type_map = [
            'App\Models\Customer' => 'customer',
            'App\Models\Asset' => 'asset',
            'App\Models\Product' => 'product',
            'App\Models\ServiceOrder' => 'service_order',
            'App\Models\Ticket' => 'ticket',
        ];

        $model_type = $model_type_map[get_class($this)] ?? null;

        if (!$model_type) {
            return [];
        }

        $all_fields = CustomField::where('model_type', $model_type)
            ->orderBy('sort_order')
            ->get();

        $attached = $this->customFields->keyBy('id');

        return $all_fields->map(function ($field) use ($attached) {
            $field_array = $field->toArray();
            $field_array['pivot'] = [
                'value' => $attached->has($field->id) ? $attached->get($field->id)->pivot->value : null,
            ];
            return $field_array;
        })->all();
    }
}

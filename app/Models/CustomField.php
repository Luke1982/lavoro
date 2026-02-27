<?php

namespace App\Models;

use App\Enums\CustomFieldTypes;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rule;

class CustomField extends Model
{
    protected $fillable = [
        'name',
        'model_type',
        'field_type',
        'options',
        'required',
        'sort_order',
    ];

    protected $casts = [
        'options' => 'array',
        'required' => 'boolean',
    ];

    public static function targetModelOptions(): array
    {
        return [
            ['id' => 'customer', 'name' => 'Klant'],
            ['id' => 'asset', 'name' => 'Machine'],
            ['id' => 'product', 'name' => 'Product'],
            ['id' => 'service_order', 'name' => 'Werkbon'],
            ['id' => 'ticket', 'name' => 'Storing'],
        ];
    }

    public static function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'model_type' => ['required', 'string', Rule::in(array_column(self::targetModelOptions(), 'id'))],
            'field_type' => ['required', 'string', Rule::in(array_column(CustomFieldTypes::cases(), 'name'))],
            'options' => ['nullable', 'array'],
            'options.*' => ['string', 'max:255'],
            'required' => ['boolean'],
            'sort_order' => ['integer', 'min:0'],
        ];
    }
}

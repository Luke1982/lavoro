<?php

namespace App\Http\Requests\Concerns;

use App\Models\ServiceOrderTaskCheck;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Validator;

/**
 * A new order for the links inside one checklist or on one task. Links of
 * different owners cannot share an order.
 */
trait ReordersLinks
{
    /** @return class-string<Model> */
    abstract protected function linkModel(): string;

    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', ServiceOrderTaskCheck::class);
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['integer', 'distinct'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                if ($validator->errors()->isEmpty() && !$this->linkModel()::shareOneOwner($this->input('ids'))) {
                    $validator->errors()->add('ids', 'Deze koppelingen horen niet bij dezelfde lijst.');
                }
            },
        ];
    }
}

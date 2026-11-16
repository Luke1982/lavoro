<?php

namespace App\Http\Requests\Tray;

use App\Domain\Tray\TrayKinds;
use App\Models\TrayItem;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class TrayStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', TrayItem::class);
    }

    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', Rule::in(TrayKinds::names())],
            'ids' => ['required', 'array', 'min:1', 'max:100'],
            'ids.*' => ['integer'],
        ];
    }
}

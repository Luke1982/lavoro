<?php

namespace App\Http\Requests\Tray;

use Illuminate\Foundation\Http\FormRequest;

class TrayItemUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('tray_item'));
    }

    public function rules(): array
    {
        return [
            'pinned' => ['required', 'boolean'],
        ];
    }
}

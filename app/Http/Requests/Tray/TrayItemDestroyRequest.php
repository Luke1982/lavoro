<?php

namespace App\Http\Requests\Tray;

use Illuminate\Foundation\Http\FormRequest;

class TrayItemDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('tray_item'));
    }

    public function rules(): array
    {
        return [];
    }
}

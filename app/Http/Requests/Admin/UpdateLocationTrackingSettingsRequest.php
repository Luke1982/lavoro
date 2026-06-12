<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLocationTrackingSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'start'   => ['required', 'date_format:H:i'],
            'end'     => ['required', 'date_format:H:i'],
            'days'    => ['required', 'array', 'min:1'],
            'days.*'  => ['integer', 'between:1,7'],
        ];
    }
}

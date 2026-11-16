<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A switch on the general settings page: only an admin flips it, and it sends a plain yes
 * or no as `value`.
 */
class UpdateBooleanSettingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'value' => ['required', 'boolean'],
        ];
    }
}

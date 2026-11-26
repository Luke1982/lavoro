<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Replacing a company's logo, as changing the company.
 *
 * @method \App\Models\User|null user(string $guard = null)
 */
class CompanyLogoRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('update', $this->route('company'));
    }

    public function rules(): array
    {
        return [
            'logo' => 'required|image|max:2048',
        ];
    }
}

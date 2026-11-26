<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing a company.
 *
 * @method \App\Models\User|null user(string $guard = null)
 */
class CompanyDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('delete', $this->route('company'));
    }

    public function rules(): array
    {
        return [];
    }
}

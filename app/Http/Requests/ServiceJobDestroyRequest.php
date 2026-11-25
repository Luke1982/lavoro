<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Removing a keuring, with the parts combined under it.
 *
 * @method \App\Models\User user($guard = null)
 */
class ServiceJobDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('delete', $this->route('servicejob'));
    }

    public function rules(): array
    {
        return [];
    }
}

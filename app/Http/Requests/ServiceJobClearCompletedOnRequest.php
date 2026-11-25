<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening a finished keuring again is changing it.
 *
 * @method \App\Models\User user($guard = null)
 */
class ServiceJobClearCompletedOnRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('update', $this->route('servicejob'));
    }

    public function rules(): array
    {
        return [];
    }
}

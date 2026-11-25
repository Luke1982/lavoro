<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Opening a keuring: one on a werkbon this person may open.
 *
 * @method \App\Models\User user($guard = null)
 */
class ServiceJobShowRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('servicejob'));
    }

    public function rules(): array
    {
        return [];
    }
}

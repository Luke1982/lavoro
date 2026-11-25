<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding the keurpunten its product type has gained since is changing the keuring.
 *
 * @method \App\Models\User user($guard = null)
 */
class ServiceJobAddMissingInstancesRequest extends FormRequest
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

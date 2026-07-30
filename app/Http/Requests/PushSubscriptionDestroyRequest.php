<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The browser identifies its own subscription by endpoint, which is all it is
 * given; the row it may forget is then narrowed to its owner in the controller.
 */
class PushSubscriptionDestroyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:500'],
        ];
    }
}

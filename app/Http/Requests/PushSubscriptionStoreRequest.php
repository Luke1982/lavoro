<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Registering the browser you are sitting at needs no permission beyond being
 * signed in, the same bar the native app's device tokens are held to. Nothing is
 * disclosed by it: the row only says where this person can be reached.
 */
class PushSubscriptionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'url', 'max:500'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
            'content_encoding' => ['sometimes', 'in:aes128gcm,aesgcm'],
        ];
    }

    public function messages(): array
    {
        return [
            'endpoint.required' => 'Het abonnement mist een endpoint.',
            'keys.p256dh.required' => 'Het abonnement mist de encryptiesleutel.',
            'keys.auth.required' => 'Het abonnement mist het authenticatiegeheim.',
        ];
    }
}

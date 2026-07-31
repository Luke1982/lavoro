<?php

namespace App\Http\Requests;

use App\Models\NotificationSubscription;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Iedereen mag zijn eigen voorkeuren bekijken. Die van een ander erbij pakken is
 * hetzelfde recht dat nodig is om ze te wijzigen, dus dat wordt hier gevraagd en
 * niet pas bij het omzetten van een schakelaar.
 */
class NotificationSubscriptionListRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        if (!$user) {
            return false;
        }

        if (!$this->filled('user_id') || (int) $this->input('user_id') === $user->id) {
            return true;
        }

        return $user->can('manageOthers', NotificationSubscription::class);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
        ];
    }
}

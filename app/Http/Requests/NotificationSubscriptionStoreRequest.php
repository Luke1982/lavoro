<?php

namespace App\Http\Requests;

use App\Enums\UserNotificationType;
use App\Models\NotificationSubscription;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationSubscriptionStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('create', [NotificationSubscription::class, $this->subscriber()]);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'type' => [
                'required',
                Rule::in(array_column(UserNotificationType::cases(), 'value')),
                Rule::unique('notification_subscriptions')
                    ->where(fn ($query) => $query->where('user_id', $this->subscriberId())),
                fn (string $attribute, mixed $value, callable $fail) => $this->rejectUnreadableType($value, $fail),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'type.required' => 'Het meldingstype is verplicht.',
            'type.in' => 'Dit meldingstype bestaat niet.',
            'type.unique' => 'Dit abonnement bestaat al.',
            'user_id.exists' => 'De opgegeven gebruiker bestaat niet.',
        ];
    }

    /**
     * Nobody, themselves included, is signed up for news they may not read. The
     * warning names the person, because the one setting this is often not the one
     * who would be missing the permission.
     */
    private function rejectUnreadableType(mixed $value, callable $fail): void
    {
        $subscriber = $this->subscriber();
        $type = is_string($value) ? UserNotificationType::tryFrom($value) : null;

        if (!$subscriber || !$type) {
            return;
        }

        $permission = $type->requiredPermission();

        if ($permission === null || $subscriber->hasPermission($permission)) {
            return;
        }

        $fail($subscriber->id === $this->user()->id
            ? 'Je hebt de rechten niet om meldingen van het type ' . $type->label() . ' te ontvangen.'
            : $subscriber->name . ' heeft de rechten niet om meldingen van het type ' . $type->label() . ' te ontvangen.');
    }

    /**
     * The subscription is for whoever is named, and for the person asking when
     * nobody is.
     */
    public function subscriber(): ?User
    {
        $user_id = $this->input('user_id');

        return $user_id === null ? $this->user() : User::find($user_id);
    }

    private function subscriberId(): ?int
    {
        return $this->subscriber()?->id;
    }
}

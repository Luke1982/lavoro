<?php

namespace App\Http\Requests;

use App\Enums\UserNotificationType;
use App\Models\NotificationSubscription;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationSubscriptionStoreRequest extends FormRequest
{
    /** False until looked up, which is not the same as looked up and not found. */
    private User|false|null $resolved_subscriber = false;

    public function authorize(): bool
    {
        return $this->user()->can('create', [NotificationSubscription::class, $this->subscriber()]);
    }

    public function rules(): array
    {
        return [
            /**
             * Trashed users are excluded on purpose: a plain exists rule counts
             * them, and the row would then pass validation only to be looked up as
             * nothing at all.
             */
            'user_id' => [
                'nullable',
                'integer',
                Rule::exists('users', 'id')->whereNull('deleted_at'),
            ],
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

        $about = 'de rechten niet om meldingen van het type ' . $type->label() . ' te ontvangen.';

        $fail($subscriber->id === $this->user()->id
            ? 'Je hebt ' . $about
            : $subscriber->name . ' heeft ' . $about);
    }

    /**
     * The subscription is for whoever is named, and for the person asking when
     * nobody is. Resolved once: authorisation, two rules and the controller all
     * ask, and none of them needs its own query to find out.
     */
    public function subscriber(): ?User
    {
        if ($this->resolved_subscriber !== false) {
            return $this->resolved_subscriber;
        }

        $user_id = $this->input('user_id');

        return $this->resolved_subscriber = $user_id === null
            ? $this->user()
            : User::find($user_id);
    }

    private function subscriberId(): ?int
    {
        return $this->subscriber()?->id;
    }
}

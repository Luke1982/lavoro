<?php

namespace App\Http\Requests;

use App\Enums\UserNotificationType;
use App\Http\Requests\Concerns\ResolvesNotificationSubscriber;
use App\Models\NotificationMute;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class NotificationMuteStoreRequest extends FormRequest
{
    use ResolvesNotificationSubscriber;

    public function authorize(): bool
    {
        return $this->user()->can('create', [NotificationMute::class, $this->subscriber()]);
    }

    public function rules(): array
    {
        return [
            'user_id' => ['nullable', 'integer', Rule::exists('users', 'id')->whereNull('deleted_at')],
            'type' => [
                'required',
                Rule::in(array_column(UserNotificationType::mutableCases(), 'value')),
                Rule::unique('notification_mutes', 'type')->where('user_id', $this->subscriber()?->id),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'user_id.exists' => 'De opgegeven gebruiker bestaat niet.',
            'type.required' => 'Het meldingstype is verplicht.',
            'type.in' => 'Deze melding kan niet uitgezet worden.',
            'type.unique' => 'Deze melding staat al uit.',
        ];
    }
}

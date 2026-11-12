<?php

namespace App\Http\Requests\Admin;

use App\Services\AppointmentReminders;
use Illuminate\Foundation\Http\FormRequest;

class UpdateAppointmentReminderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->isAdmin() ?? false;
    }

    public function rules(): array
    {
        return [
            'minutes' => [
                'required',
                'integer',
                'min:' . AppointmentReminders::MIN_MINUTES,
                'max:' . AppointmentReminders::MAX_MINUTES,
            ],
        ];
    }

    public function messages(): array
    {
        $range = AppointmentReminders::MIN_MINUTES . ' en ' . AppointmentReminders::MAX_MINUTES;

        return [
            'minutes.required' => 'Vul in hoeveel minuten van tevoren.',
            'minutes.integer' => 'Vul een heel aantal minuten in.',
            'minutes.min' => 'Kies een tijd tussen ' . $range . ' minuten.',
            'minutes.max' => 'Kies een tijd tussen ' . $range . ' minuten.',
        ];
    }
}

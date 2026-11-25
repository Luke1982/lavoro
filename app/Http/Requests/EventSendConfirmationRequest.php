<?php

namespace App\Http\Requests;

use App\Models\Event;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Mailing the customer a confirmation of the appointment is writing to them on
 * the company's behalf, so it takes the same right as the standard e-mails.
 */
class EventSendConfirmationRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && (bool) $this->user()?->can('sendStandardEmail', $event);
    }

    public function rules(): array
    {
        return [];
    }
}

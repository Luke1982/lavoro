<?php

namespace App\Http\Requests;

use App\Actions\Appointments\CopyAppointmentAction;
use App\Models\Event;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $event = $this->route('event');

        return $event instanceof Event && (bool) $this->user()?->can('copy', $event);
    }

    public function rules(): array
    {
        return [
            'offsets' => 'required_without:date|array|min:1|max:' . CopyAppointmentAction::MOST_COPIES,
            'offsets.*' => 'required|integer|distinct|min:1|max:' . CopyAppointmentAction::MOST_DAYS_AWAY,
            'date' => [
                'bail',
                'required_without:offsets',
                'prohibits:offsets',
                Rule::date()->format('Y-m-d'),
                function (string $attribute, mixed $value, Closure $fail) {
                    $refusal = CopyAppointmentAction::refusalFor($this->route('event'), CarbonImmutable::parse($value));

                    if ($refusal !== null) {
                        $fail($refusal);
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required_without' => 'Kies een datum.',
            'date.date_format' => 'Kies een geldige datum.',
        ];
    }

    /**
     * How many days each copy moves: the offsets as sent, or the one that lands
     * the copy on the chosen date.
     *
     * @return array<int, int>
     */
    public function offsets(): array
    {
        $date = $this->validated('date');

        return $date === null
            ? $this->validated('offsets')
            : [CopyAppointmentAction::offsetTo($this->route('event'), CarbonImmutable::parse($date))];
    }
}

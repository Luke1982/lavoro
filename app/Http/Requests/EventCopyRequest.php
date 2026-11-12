<?php

namespace App\Http\Requests;

use App\Domain\Planning\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class EventCopyRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user && ($user->isAdmin() || $user->hasPermission('event.create'));
    }

    public function rules(): array
    {
        $event_day = $this->eventDay();

        return [
            'offsets' => 'required_without:date|array|min:1',
            'offsets.*' => 'required|integer|min:1|max:365',
            'date' => [
                'required_without:offsets',
                'prohibits:offsets',
                Rule::date()->format('Y-m-d')->betweenOrEqual($event_day->subDays(365), $event_day->addDays(365)),
                Rule::notIn([$event_day->toDateString()]),
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'date.required_without' => 'Kies een datum.',
            'date.date_format' => 'Kies een geldige datum.',
            'date.after_or_equal' => 'Kies een datum binnen een jaar van de afspraak.',
            'date.before_or_equal' => 'Kies een datum binnen een jaar van de afspraak.',
            'date.not_in' => 'De afspraak staat al op deze dag; kies een andere datum.',
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
            : [(int) $this->eventDay()->diffInDays($date)];
    }

    /**
     * The calendar day the appointment is on where the work happens, as a plain
     * date, so counting days to another date never meets a clock change.
     */
    private function eventDay(): CarbonImmutable
    {
        return CarbonImmutable::parse(Clock::toLocal($this->route('event')->start)->toDateString(), 'UTC');
    }
}

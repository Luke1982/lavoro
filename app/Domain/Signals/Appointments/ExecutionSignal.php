<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Planning\Clock;
use App\Domain\Signals\BaseSignal;
use App\Models\Event;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Iets veranderde aan het deel van één monteur in een afspraak: de status of de
 * geregistreerde tijden. Die staan in event_user_executions, een tabel zonder
 * RecordsHistory. Zonder deze signalen stond nergens wie zijn deel annuleerde of
 * wie tijden vrijgaf, en kon de assistent alleen zeggen dat hij het niet wist.
 *
 * Draagt de stand van vóór en na de handeling, zodat te zien blijft welke tijden
 * er stonden voordat ze werden vrijgegeven.
 *
 * Implementeert AppointmentSignal niet. Op die verzameling luisteren de
 * standaardmails en de Google-koppeling, en wat een monteur over zijn eigen uren
 * vastlegt is voor geen van beide een aanleiding.
 */
abstract class ExecutionSignal extends BaseSignal
{
    private const FIELD_LABELS = [
        'completion_status' => 'Status',
        'actual_start' => 'Starttijd',
        'actual_end' => 'Eindtijd',
        'travel_time_minutes' => 'Reistijd',
        'actual_breaktime_minutes' => 'Pauze',
    ];

    /**
     * @param  array<string, string|int|null>  $before  EventUserExecution::snapshot() before the action
     * @param  array<string, string|int|null>  $after  EventUserExecution::snapshot() after it
     */
    public function __construct(
        public Event $event,
        public User $executor,
        public array $before,
        public array $after,
    ) {
        parent::__construct();
    }

    public function subject(): Model
    {
        return $this->event;
    }

    public function activityCategory(): string
    {
        return 'status';
    }

    /**
     * Een afspraak heeft geen eigen tijdlijn op het scherm. Wie wil weten waarom
     * een monteur geen tijden heeft, kijkt op de werkbon, dus daar hoort dit ook.
     */
    public function activityContext(): array
    {
        return $this->event->serviceOrders->all();
    }

    public function activityMetadata(): ?array
    {
        return ['executor_id' => $this->executor->getKey()];
    }

    public function changes(): array
    {
        $changes = [];

        foreach (self::FIELD_LABELS as $field => $label) {
            $old_value = $this->before[$field] ?? null;
            $new_value = $this->after[$field] ?? null;

            if ($old_value === $new_value) {
                continue;
            }

            $changes[] = [
                'field' => $field,
                'label' => $label . ' (' . $this->executor->name . ')',
                'old_value' => $old_value === null ? null : (string) $old_value,
                'new_value' => $new_value === null ? null : (string) $new_value,
                'old_label' => $this->readable($field, $old_value),
                'new_label' => $this->readable($field, $new_value),
            ];
        }

        return $changes;
    }

    /** @param array<string, string|int|null> $state */
    protected function timesSentence(array $state): string
    {
        $sentence = ($this->clockTime($state['actual_start']) ?? '-')
            . ' – ' . ($this->clockTime($state['actual_end']) ?? '-');

        if ($state['travel_time_minutes'] > 0) {
            $sentence .= ', reistijd ' . $state['travel_time_minutes'] . ' min';
        }

        if ($state['actual_breaktime_minutes'] !== null) {
            $sentence .= ', pauze ' . $state['actual_breaktime_minutes'] . ' min';
        }

        return $sentence;
    }

    protected function clockTime(?string $stored): ?string
    {
        return $stored === null ? null : Clock::toLocal($stored)->format('H:i');
    }

    private function readable(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return match ($field) {
            'actual_start', 'actual_end' => $this->clockTime($value),
            'travel_time_minutes', 'actual_breaktime_minutes' => $value . ' min',
            default => (string) $value,
        };
    }
}

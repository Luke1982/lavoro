<?php

namespace App\Domain\Signals\Appointments;

use App\Domain\Signals\BaseSignal;
use App\Enums\EventTrigger;
use App\Models\Event;
use App\Models\GoogleSyncedEvent;
use Illuminate\Database\Eloquent\Model;

/**
 * An appointment was removed. `permanent` separates a soft delete, which the
 * user can undo, from a force delete, which nothing can.
 */
class AppointmentCancelled extends BaseSignal implements AppointmentSignal
{
    /** @var array<int, array{id: int, calendar_id: int, google_event_id: string}> */
    public array $synced_mappings;

    public function __construct(
        public Event $event,
        public bool $permanent = false,
    ) {
        parent::__construct();

        $this->synced_mappings = GoogleSyncedEvent::where('event_id', $event->id)
            ->get()
            ->map(fn (GoogleSyncedEvent $mapping) => [
                'id' => $mapping->id,
                'calendar_id' => $mapping->google_synced_calendar_id,
                'google_event_id' => $mapping->google_event_id,
            ])
            ->all();
    }

    public static function key(): string
    {
        return 'appointment.cancelled';
    }

    public static function label(): string
    {
        return 'Afspraak verwijderd';
    }

    public function appointment(): Event
    {
        return $this->event;
    }

    public function emailTrigger(): ?EventTrigger
    {
        return $this->permanent ? null : EventTrigger::event_deleted;
    }

    public function appointmentStillExists(): bool
    {
        return false;
    }

    public function subject(): Model
    {
        return $this->event;
    }

    public function activityDescription(): ?string
    {
        return null;
    }
}

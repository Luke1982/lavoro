<?php

namespace App\Enums;

use App\Domain\Signals\Signal;
use App\Models\Ticket;
use Illuminate\Support\Str;

/**
 * The facts somebody can ask to be told about.
 *
 * The case value is the signal's event key, because that is what is matched
 * against Signal::eventKey() and what is stored on the notification itself. That
 * makes this the one enum here whose value is not the Dutch label: a key that is
 * persisted and compared cannot also be a sentence somebody might reword. The
 * label is a method instead.
 *
 * Adding a type is adding a case and then answering, for that case, every method
 * below: its label, the permission its readers must hold, its two sentences and
 * how urgent it is. Nothing else changes — the listener already receives every
 * signal the application raises — and a case that forgets one of them fails
 * loudly, because an unhandled match has nowhere to go.
 */
enum UserNotificationType: string
{
    case ticket_created = 'ticket.created';

    public function label(): string
    {
        return match ($this) {
            self::ticket_created => 'Nieuwe storing',
        };
    }

    /**
     * The permission a reader must hold for this type. Checked when a
     * subscription is set and again when the notification is written, so it is
     * the permission and not the subscription that decides.
     */
    public function requiredPermission(): ?string
    {
        return match ($this) {
            self::ticket_created => 'ticket.read',
        };
    }

    /**
     * Het pictogram en de kleur waarin dit type in de lijst verschijnt. Namen,
     * geen klassen: de front end vertaalt ze, zodat een kleur veranderen hier
     * gebeurt en niet in een template.
     */
    public function icon(): string
    {
        return match ($this) {
            self::ticket_created => 'Wrench',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ticket_created => 'amber',
        };
    }

    /**
     * De titel is een kort etiket en niet de hele melding: in de lijst staat er
     * één regel voor, en een onderwerp dat daar niet in past wordt afgekapt op
     * het woord waar het interessant werd. Wat het is, staat eronder.
     */
    public function titleFor(Signal $signal): string
    {
        return match ($this) {
            self::ticket_created => 'Nieuwe storing #' . $this->ticket($signal)->id,
        };
    }

    public function bodyFor(Signal $signal): string
    {
        return match ($this) {
            self::ticket_created => $this->ticketCreatedBody($signal),
        };
    }

    /**
     * Read off the occurrence rather than fixed per type, so an urgent storing
     * arrives as an urgent notification instead of being levelled out to whatever
     * storingen are worth on average.
     */
    public function priorityFor(Signal $signal): UserNotificationPriority
    {
        return match ($this) {
            self::ticket_created => UserNotificationPriority::fromTicketPriority(
                $this->ticket($signal)->priority
            ),
        };
    }

    /** @return array<int, array<string, string>> */
    public static function comboBoxArray(): array
    {
        return array_map(
            fn (self $case) => ['id' => $case->value, 'name' => $case->label()],
            self::cases()
        );
    }

    private function ticketCreatedBody(Signal $signal): string
    {
        $ticket = $this->ticket($signal);
        $machine = $ticket->asset?->serial_number;

        return Str::limit($ticket->subject, 120)
            . ($machine ? ' — machine ' . $machine : '')
            . ', gemeld door ' . ($signal->actorName() ?? 'het systeem');
    }

    private function ticket(Signal $signal): Ticket
    {
        return $signal->subject();
    }
}

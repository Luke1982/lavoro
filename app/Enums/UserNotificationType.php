<?php

namespace App\Enums;

use App\Domain\Signals\ServiceOrders\MaterialAttachedToOrder;
use App\Domain\Signals\ServiceOrders\ServiceOrderStageChanged;
use App\Domain\Signals\Signal;
use App\Domain\Signals\Tasks\TaskSigned;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use Illuminate\Support\Str;

/**
 * De feiten waarover iemand bericht kan krijgen.
 *
 * De waarde van een case is de sleutel van het signaal, want daarop wordt
 * vergeleken en die wordt bewaard op de melding zelf. Dat maakt dit de enige enum
 * hier waarvan de waarde niet het Nederlandse label is: een sleutel die opgeslagen
 * en vergeleken wordt kan niet ook een zin zijn die iemand herschrijft.
 *
 * Een soort toevoegen is een case toevoegen en daarna elke methode hieronder voor
 * die case beantwoorden. Verder verandert er niets: de luisteraar krijgt elk
 * signaal dat de applicatie afgeeft toch al binnen.
 */
enum UserNotificationType: string
{
    case ticket_created = 'ticket.created';
    case appointment_scheduled = 'appointment.scheduled';
    case appointment_rescheduled = 'appointment.rescheduled';
    case serviceorder_closed = 'serviceorder.stage_changed';
    case material_attached = 'serviceorder.material_attached';
    case task_signed = 'taskinstance.signed';
    case customer_created = 'customer.created';

    public function label(): string
    {
        return match ($this) {
            self::ticket_created => 'Nieuwe storing',
            self::appointment_scheduled => 'Nieuwe planning',
            self::appointment_rescheduled => 'Planning gewijzigd',
            self::serviceorder_closed => 'Werkbon afgerond',
            self::material_attached => 'Materiaal toegevoegd',
            self::task_signed => 'Keuring ondertekend',
            self::customer_created => 'Nieuwe klant',
        };
    }

    /** Waar het over gaat, één regel, voor het scherm waar je ze aanzet. */
    public function description(): string
    {
        return match ($this) {
            self::ticket_created => 'Zodra er een storing wordt gemeld.',
            self::appointment_scheduled => 'Zodra er een afspraak wordt ingepland.',
            self::appointment_rescheduled => 'Zodra een afspraak wordt verzet.',
            self::serviceorder_closed => 'Zodra een werkbon wordt afgerond.',
            self::material_attached => 'Zodra er materiaal op een werkbon komt.',
            self::task_signed => 'Zodra een keuring wordt ondertekend.',
            self::customer_created => 'Zodra er een klant wordt toegevoegd.',
        };
    }

    /**
     * Het recht dat een lezer moet hebben. Gecontroleerd bij het aanzetten én
     * opnieuw bij het schrijven, zodat het recht beslist en niet het abonnement.
     */
    public function requiredPermission(): ?string
    {
        return match ($this) {
            self::ticket_created => 'ticket.read',
            self::appointment_scheduled, self::appointment_rescheduled => 'event.read',
            self::serviceorder_closed, self::material_attached, self::task_signed => 'serviceorder.read',
            self::customer_created => 'customer.read',
        };
    }

    /**
     * Niet elk signaal is ook een bericht waard. Een werkbon wisselt vaak van fase
     * en alleen de laatste stap is nieuws; de rest van de soorten gaat altijd door.
     */
    public function shouldNotify(Signal $signal): bool
    {
        return match ($this) {
            self::serviceorder_closed => $signal instanceof ServiceOrderStageChanged
                && (bool) $signal->new_stage?->is_closed_state,
            default => true,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::ticket_created => 'Wrench',
            self::appointment_scheduled, self::appointment_rescheduled => 'CalendarDays',
            self::serviceorder_closed => 'ClipboardCheck',
            self::material_attached => 'Box',
            self::task_signed => 'ClipboardCheck',
            self::customer_created => 'Users',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ticket_created => 'amber',
            self::appointment_scheduled => 'blue',
            self::appointment_rescheduled => 'amber',
            self::serviceorder_closed, self::task_signed => 'green',
            self::material_attached => 'purple',
            self::customer_created => 'blue',
        };
    }

    /**
     * De titel is een kort etiket en niet de hele melding: er staat één regel voor
     * in de lijst. Wat het is, staat eronder.
     */
    public function titleFor(Signal $signal): string
    {
        return match ($this) {
            self::ticket_created => 'Nieuwe storing #' . $this->ticket($signal)->id,
            self::appointment_scheduled => 'Nieuwe planning',
            self::appointment_rescheduled => 'Planning gewijzigd',
            self::serviceorder_closed => 'Werkbon #' . $this->serviceOrder($signal)->id . ' afgerond',
            self::material_attached => 'Materiaal op werkbon #' . $this->serviceOrder($signal)->id,
            self::task_signed => 'Keuring ondertekend',
            self::customer_created => 'Nieuwe klant',
        };
    }

    public function bodyFor(Signal $signal): string
    {
        return match ($this) {
            self::ticket_created => $this->ticketCreatedBody($signal),
            self::appointment_scheduled, self::appointment_rescheduled => $this->appointmentBody($signal),
            self::serviceorder_closed => $this->serviceOrderClosedBody($signal),
            self::material_attached => $this->materialBody($signal),
            self::task_signed => $this->taskSignedBody($signal),
            self::customer_created => $this->customerBody($signal),
        };
    }

    /**
     * Van de gebeurtenis zelf afgelezen en niet vast per soort, zodat een dringende
     * storing ook als dringend binnenkomt.
     */
    public function priorityFor(Signal $signal): UserNotificationPriority
    {
        return match ($this) {
            self::ticket_created => UserNotificationPriority::fromTicketPriority($this->ticket($signal)->priority),
            self::appointment_scheduled, self::appointment_rescheduled => UserNotificationPriority::normaal,
            default => UserNotificationPriority::laag,
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
            . ', gemeld door ' . $this->actor($signal);
    }

    private function appointmentBody(Signal $signal): string
    {
        $event = $this->event($signal);
        $when = $event->start ? $event->start->format('d-m-Y H:i') : 'onbekende datum';

        return ($event->name ?: 'Afspraak') . ' op ' . $when . ', door ' . $this->actor($signal);
    }

    private function serviceOrderClosedBody(Signal $signal): string
    {
        $order = $this->serviceOrder($signal);
        $stage = $signal instanceof ServiceOrderStageChanged ? $signal->new_stage?->name : null;

        return ($order->customer?->name ? 'Voor ' . $order->customer->name : 'Werkbon')
            . ($stage ? ' — fase ' . $stage : '')
            . ', afgerond door ' . $this->actor($signal);
    }

    private function materialBody(Signal $signal): string
    {
        if (!$signal instanceof MaterialAttachedToOrder) {
            return 'Materiaal toegevoegd door ' . $this->actor($signal);
        }

        return rtrim(rtrim(number_format($signal->quantity, 2, ',', ''), '0'), ',')
            . 'x ' . $signal->item_name . ' toegevoegd door ' . $this->actor($signal);
    }

    private function taskSignedBody(Signal $signal): string
    {
        if (!$signal instanceof TaskSigned) {
            return 'Ondertekend door ' . $this->actor($signal);
        }

        return $signal->task_title . ' op werkbon #' . $signal->service_order->id
            . ', ondertekend door ' . ($signal->signed_by ?: $this->actor($signal));
    }

    private function customerBody(Signal $signal): string
    {
        $customer = $signal->subject();

        return ($customer->name ?? 'Klant') . ' is toegevoegd door ' . $this->actor($signal);
    }

    private function actor(Signal $signal): string
    {
        return $signal->actorName() ?? 'het systeem';
    }

    private function ticket(Signal $signal): Ticket
    {
        return $signal->subject();
    }

    private function event(Signal $signal): Event
    {
        return $signal->subject();
    }

    /**
     * De werkbon staat bij het ene signaal in het onderwerp en bij het andere als
     * eigen veld; beide wijzen dezelfde bon aan.
     */
    private function serviceOrder(Signal $signal): ServiceOrder
    {
        if ($signal instanceof ServiceOrderStageChanged) {
            return $signal->service_order;
        }

        if ($signal instanceof MaterialAttachedToOrder || $signal instanceof TaskSigned) {
            return $signal->service_order;
        }

        return $signal->subject();
    }
}

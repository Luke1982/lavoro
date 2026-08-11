<?php

namespace App\Enums;

use App\Domain\Signals\Appointments\AppointmentRescheduled;
use App\Domain\Signals\Appointments\AppointmentUnassigned;
use App\Domain\Signals\ServiceOrders\MaterialAttachedToOrder;
use App\Domain\Signals\ServiceOrders\ServiceOrderCloseBlockedByHiddenTasks;
use App\Domain\Signals\ServiceOrders\ServiceOrderStageChanged;
use App\Domain\Signals\Signal;
use App\Domain\Signals\Tasks\TaskSigned;
use App\Models\Event;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Support\Collection;
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
    case close_blocked_by_hidden_tasks = 'serviceorder.close_blocked_by_hidden_tasks';

    /**
     * Deze hangt aan geen enkel signaal: er gebeurt niets op het moment dat een
     * afspraak voorbij is zonder ingevulde tijden. Die wordt geschreven
     * door notifications:missing-times, met zijn eigen tekst, want dat commando
     * weet welke afspraak en welke monteur het betreft.
     */
    case execution_times_missing = 'eventexecution.times_missing';

    /**
     * De tweede zonder eigen signaal. Een verzette afspraak geeft wel een signaal
     * af, maar dat gaat over elke wijziging aan elke afspraak en is er één waarop
     * je intekent; dit gaat over jouw dag die verschuift en komt daarom hoe dan
     * ook. Twee gezichten van hetzelfde moment, dus twee soorten.
     */
    case own_appointment_moved = 'appointment.moved_for_executor';

    /**
     * Heeft wel een eigen signaal maar draagt niet zijn sleutel, want niet de
     * sleutel wijst hier de lezers aan: het signaal brengt de namen mee van wie
     * eraf gehaald is, en die staan al niet meer bij de afspraak.
     *
     * Zegt alleen dat de afspraak niet meer van jou is. Niet naar wie hij gegaan
     * is en niet naar wanneer hij verzet is: wie eraf gehaald wordt kan de afspraak
     * daarna niet meer inzien, en een bericht mag niet vertellen wat het scherm
     * erachter terecht verzwijgt.
     */
    case removed_from_appointment = 'appointment.removed_for_executor';

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
            self::close_blocked_by_hidden_tasks => 'Werkbon niet te sluiten',
            self::execution_times_missing => 'Tijden nog niet ingevuld',
            self::own_appointment_moved => 'Jouw afspraak verplaatst',
            self::removed_from_appointment => 'Van afspraak afgehaald',
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
            self::close_blocked_by_hidden_tasks => 'Als iemand een werkbon niet kan sluiten '
                . 'door taken die diegene zelf niet mag zien.',
            self::execution_times_missing => 'Als je tijden van een afspraak van gisteren of eerder nog openstaan.',
            self::own_appointment_moved => 'Als een afspraak waarop jij staat een andere datum of tijd krijgt.',
            self::removed_from_appointment => 'Als een afspraak niet langer op jouw naam staat.',
        };
    }

    /**
     * Of je hier zelf iets over te zeggen hebt. Bijna alles is nieuws waar je op
     * intekent, maar wat over je eigen werk gaat is geen nieuws: je openstaande uren
     * en je eigen verzette afspraak krijg je te horen omdat het werk is dat gedaan
     * moet worden. Een schakelaar ernaast zou beloven dat je hem uit kunt zetten,
     * en dat is niet zo.
     */
    public function subscribable(): bool
    {
        return match ($this) {
            self::execution_times_missing, self::own_appointment_moved,
            self::removed_from_appointment => false,
            default => true,
        };
    }

    /** @return array<int, self> */
    public static function subscribableCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => $case->subscribable()
        ));
    }

    /** @return array<int, self> */
    public static function unconditionalCases(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $case) => !$case->subscribable()
        ));
    }

    /**
     * De soorten die dit signaal hoe dan ook oplevert, los van wie zich waarvoor
     * heeft aangemeld. Een lege lijst is het normale geval: bijna alles is nieuws
     * waar je op intekent.
     *
     * @return array<int, self>
     */
    public static function mandatoryFor(Signal $signal): array
    {
        return match (true) {
            $signal instanceof AppointmentRescheduled && $signal->moved_in_time => [self::own_appointment_moved],
            $signal instanceof AppointmentUnassigned && $signal->removed_user_ids !== [] => [self::removed_from_appointment],
            default => [],
        };
    }

    /**
     * Wie zo'n verplichte melding krijgt. Alleen de mensen die de afspraak moeten
     * uitvoeren: het is hun dag die verschuift. Verwijderde gebruikers vallen af,
     * want de relatie houdt ze met opzet vast voor de geschiedenis en dat is iets
     * anders dan iemand die nog bericht moet krijgen.
     *
     * @return Collection<int, int>
     */
    public function mandatoryRecipients(Signal $signal): Collection
    {
        return match ($this) {
            self::own_appointment_moved => $this->event($signal)
                ->executingUsers()
                ->whereNull('users.deleted_at')
                ->pluck('users.id')
                ->map(fn ($id) => (int) $id),

            /**
             * Deze mensen staan al niet meer bij de afspraak, dus ze komen van het
             * signaal en niet uit de tabel.
             */
            self::removed_from_appointment => $signal instanceof AppointmentUnassigned
                ? User::whereIn('id', $signal->removed_user_ids)->pluck('id')->map(fn ($id) => (int) $id)
                : collect(),

            default => collect(),
        };
    }

    /**
     * De rechten die een lezer moet hebben, allemaal. Gecontroleerd bij het
     * aanzetten én opnieuw bij het schrijven, zodat het recht beslist en niet het
     * abonnement.
     *
     * Bij afspraken zijn het er twee. Bericht krijgen over elke planning die
     * langskomt is alleen zinnig voor wie de hele planning ook mag zien: wie
     * alleen zijn eigen week kent, krijgt anders meldingen over afspraken die hij
     * daarna nergens terugvindt.
     *
     * @return array<int, string>
     */
    public function requiredPermissions(): array
    {
        return match ($this) {
            self::ticket_created => ['ticket.read'],
            self::appointment_scheduled, self::appointment_rescheduled => [
                'event.read',
                'event.see_all',
                'event.see_beyond_current_week',
            ],
            self::serviceorder_closed, self::material_attached, self::task_signed => ['serviceorder.read'],
            self::customer_created => ['customer.read'],

            /**
             * Lezen is hier niet genoeg. Het bericht vraagt om iets te doen aan een taak
             * die de melder zelf niet kwijt kan, en wie de bon niet mag bijwerken kan dat
             * evenmin: die krijgt dan een probleem te horen dat alleen doorgegeven kan
             * worden.
             */
            self::close_blocked_by_hidden_tasks => ['serviceorder.read', 'serviceorder.update'],

            /** Over je eigen uren en je eigen dag hoef je niets extra's te mogen. */
            self::execution_times_missing, self::own_appointment_moved,
            self::removed_from_appointment => [],
        };
    }

    /**
     * Niet elk signaal is ook een bericht waard. Een werkbon wisselt vaak van fase
     * en alleen de laatste stap is nieuws; een afspraak geeft hetzelfde signaal af
     * bij een verzette dag als bij een bijgewerkte omschrijving, en alleen het
     * eerste is wat hier beloofd wordt. De rest van de soorten gaat altijd door.
     */
    public function shouldNotify(Signal $signal): bool
    {
        return match ($this) {
            self::serviceorder_closed => $signal instanceof ServiceOrderStageChanged
                && (bool) $signal->new_stage?->is_closed_state,
            self::appointment_rescheduled => $signal instanceof AppointmentRescheduled
                && $signal->moved_in_time,
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
            self::close_blocked_by_hidden_tasks => 'Lock',
            self::execution_times_missing => 'Clock',
            self::own_appointment_moved => 'CalendarClock',
            self::removed_from_appointment => 'CalendarX',
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
            self::close_blocked_by_hidden_tasks => 'amber',
            self::execution_times_missing => 'red',
            self::own_appointment_moved => 'amber',
            self::removed_from_appointment => 'red',
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
            self::close_blocked_by_hidden_tasks => 'Werkbon #' . $this->serviceOrder($signal)->id
                . ' kan niet gesloten worden',
            self::execution_times_missing => 'Tijden nog niet ingevuld',
            self::own_appointment_moved => 'Jouw afspraak is verplaatst',
            self::removed_from_appointment => 'Van afspraak afgehaald',
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
            self::close_blocked_by_hidden_tasks => $this->closeBlockedBody($signal),
            self::execution_times_missing => 'Er staan nog tijden open.',
            self::own_appointment_moved => $this->ownAppointmentMovedBody($signal),
            self::removed_from_appointment => $this->removedFromAppointmentBody($signal),
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

            /** Er staat iemand stil op een bon die diegene zelf niet vlot kan trekken. */
            self::close_blocked_by_hidden_tasks => UserNotificationPriority::normaal,

            /** Jouw eigen dag die verandert is het soort bericht waarvoor je stopt. */
            self::own_appointment_moved, self::removed_from_appointment => UserNotificationPriority::hoog,
            default => UserNotificationPriority::laag,
        };
    }

    /** @return array<int, array<string, string>> */
    public static function comboBoxArray(): array
    {
        return array_map(
            fn (self $case) => ['id' => $case->value, 'name' => $case->label()],
            self::subscribableCases()
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

    /**
     * Wat er staat is dat de afspraak niet meer van jou is, en niets meer dan dat.
     * Geen nieuwe datum, want die gaat je niet meer aan, en geen naam van degene
     * die je plek inneemt, want dat is niet aan jou om te weten. Ook geen 'door
     * wie': dat zou soms de opvolger zelf zijn.
     */
    private function removedFromAppointmentBody(Signal $signal): string
    {
        $event = $this->event($signal);
        $name = $event->name ?: 'Een afspraak';

        $was = $signal instanceof AppointmentUnassigned && $signal->started_at
            ? $signal->started_at->format('d-m-Y H:i')
            : $event->start?->format('d-m-Y H:i');

        return $name . ($was ? ' van ' . $was : '') . ' staat niet meer op jouw naam.';
    }

    /**
     * Wat overbodig wordt zodra deze melding binnenkomt. Wie van een afspraak af
     * is heeft niets meer aan het bericht dat diezelfde afspraak verzet is: dat
     * wijst naar een dag die niet meer van hem is, op een scherm dat hij niet meer
     * mag zien.
     *
     * @return array<int, self>
     */
    public function supersedes(): array
    {
        return match ($this) {
            self::removed_from_appointment => [self::own_appointment_moved],
            default => [],
        };
    }

    /**
     * Een afspraak kan op twee manieren opschuiven en de zin moet zeggen welke van
     * de twee het was. Een andere begintijd is de gewone: die staat er van-naar in.
     * Blijft het begin staan en schuift alleen het eind, dan gaat het over hoe lang
     * je bezig bent, en 'verplaatst van 08:00 naar 08:00' zou een raadsel zijn.
     */
    private function ownAppointmentMovedBody(Signal $signal): string
    {
        $event = $this->event($signal);
        $was_start = $signal instanceof AppointmentRescheduled ? $signal->previous_start : null;
        $was_end = $signal instanceof AppointmentRescheduled ? $signal->previous_end : null;
        $name = $event->name ?: 'Afspraak';
        $by = ', door ' . $this->actor($signal);

        $start_stayed = $was_start && $event->start && $was_start->equalTo($event->start);

        if ($start_stayed && $was_end && $event->end) {
            return $name . ' duurt nu tot ' . $event->end->format('H:i')
                . ' in plaats van tot ' . $was_end->format('H:i') . $by;
        }

        $now = $event->start?->format('d-m-Y H:i');

        $moment = match (true) {
            $was_start !== null && $now !== null => 'van ' . $was_start->format('d-m-Y H:i') . ' naar ' . $now,
            $now !== null => 'naar ' . $now,
            default => 'naar een ander moment',
        };

        return $name . ' verplaatst ' . $moment . $by;
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

    /**
     * Noemt de taken en de rol waar ze aan hangen, want dat is precies wat de melder
     * niet kon zien en waar de lezer wel bij kan.
     */
    private function closeBlockedBody(Signal $signal): string
    {
        if (!$signal instanceof ServiceOrderCloseBlockedByHiddenTasks) {
            return $this->actor($signal) . ' kon de werkbon niet sluiten.';
        }

        $roles = $signal->role_names === []
            ? ''
            : ' (' . (count($signal->role_names) === 1 ? 'rol ' : 'rollen ')
                . implode(', ', $signal->role_names) . ')';

        return $this->actor($signal) . ' kon de werkbon niet sluiten: '
            . $signal->hiddenTaskSummary() . $roles . ($signal->isOneTask()
                ? ' staat nog open en valt'
                : ' staan nog open en vallen')
            . ' buiten de rollen waarin diegene deze bon uitvoert.';
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

        if (
            $signal instanceof MaterialAttachedToOrder
            || $signal instanceof TaskSigned
            || $signal instanceof ServiceOrderCloseBlockedByHiddenTasks
        ) {
            return $signal->service_order;
        }

        return $signal->subject();
    }
}

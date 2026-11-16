<?php

namespace App\Domain\Tray\Paste;

use App\Actions\Appointments\AppointmentAssignment;
use App\Actions\Appointments\CreateAppointmentAction;
use App\Actions\Appointments\NewAppointment;
use App\Domain\Planning\Clock;
use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\ServiceOrder;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The spot on a technician's day where something off the bakje was dropped, and
 * what every kind of thing planned there shares: which werkbonnen it can go on,
 * and the afspraak that plans the werkbon.
 */
final class PlannerSlot
{
    public const NEW_ORDER = 'new';

    private const OPEN_ORDERS_OFFERED = 5;

    public function __construct(private readonly Paste $paste) {}

    public function technician(): User
    {
        return User::findOrFail($this->paste->target['user_id']);
    }

    /** "woensdag 18 november 09:00–10:00", on the clock the planner shows. */
    public function when(): string
    {
        $start = Clock::toLocal($this->paste->target['start'] . ' UTC')->locale('nl');
        $end = Clock::toLocal($this->paste->target['end'] . ' UTC');

        return $start->isoFormat('dddd D MMMM') . ' ' . $start->format('H:i') . '–' . $end->format('H:i');
    }

    public function subtitle(): string
    {
        return 'Bij ' . $this->technician()->name . ', ' . $this->when();
    }

    /**
     * The customer's werkbonnen that are still open, newest first, as choices.
     *
     * @return array<int, Choice>
     */
    public function openOrderChoices(Customer $customer): array
    {
        return ServiceOrder::visibleTo($this->paste->user)
            ->where('customer_id', $customer->id)
            ->whereDoesntHave('serviceOrderStage', fn ($query) => $query->closesOrder())
            ->withCount('events')
            ->latest('id')
            ->limit(self::OPEN_ORDERS_OFFERED)
            ->get()
            ->map(fn (ServiceOrder $order) => $this->orderChoice($order))
            ->all();
    }

    /**
     * A werkbon to plan. Offered either as one of the customer's open ones, with
     * events_count loaded, or because it already holds what was dropped, which
     * $already then says.
     */
    public function orderChoice(ServiceOrder $order, ?string $already = null): Choice
    {
        if ($order->is_closed) {
            throw new Refusal('Werkbon ' . $order->number . ' is al afgesloten.');
        }

        $state = match (true) {
            $already !== null => $already,
            $order->events_count > 0 => 'Al eerder ingepland.',
            default => 'Nog niet ingepland.',
        };

        return new Choice(
            value: 'serviceorder:' . $order->id,
            label: ($already !== null ? 'Werkbon ' : 'Bij open werkbon ') . $order->number,
            description: ($order->description ?: 'Zonder omschrijving') . '. ' . $state,
            confirm: $already !== null ? 'Inplannen' : 'Toevoegen en inplannen',
        );
    }

    /**
     * The werkbon the answer names. Only ever one that was offered: the bakje
     * checked the answer against the question before it got here.
     */
    public function chosenOrder(): ServiceOrder
    {
        return ServiceOrder::findOrFail((int) Str::after($this->paste->answer, 'serviceorder:'));
    }

    public function wantsNewOrder(): bool
    {
        return $this->paste->answer === self::NEW_ORDER;
    }

    /** Plans the werkbon on the spot where it was dropped, through the planner's own action. */
    public function plan(ServiceOrder $order): PasteResult
    {
        $technician = $this->technician();

        app(CreateAppointmentAction::class)->execute(new NewAppointment(
            attributes: [
                'name' => mb_strimwidth($order->description ?: 'Werkbon ' . $order->number, 0, 255, '…'),
                'description' => $order->description ?? '',
                'event_type_id' => (int) $this->paste->target['event_type_id'],
                'status' => 'Gepland',
                'start' => $this->paste->target['start'],
                'end' => $this->paste->target['end'],
            ],
            eventable_type: '\\' . ServiceOrder::class,
            eventable_id: $order->id,
            assignment: new AppointmentAssignment(user_ids: [$technician->id]),
        ));

        return new PasteResult(
            'Werkbon ' . $order->number . ' ingepland bij ' . $technician->name . ', ' . $this->when() . '.',
            created: ['service_order_id' => $order->id],
        );
    }
}

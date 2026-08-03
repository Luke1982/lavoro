<?php

namespace App\Services;

use App\Models\Event;
use App\Support\AddressFormatter;
use Illuminate\Database\Eloquent\Model;

/**
 * The single definition of "where does this appointment happen".
 *
 * Every consumer (Google Calendar payload, planner export, planner UI) resolves
 * through here so the escalation order can never drift between them:
 *
 *   1. the appointment's own linked location   (most specific: picked for this visit)
 *   2. the werkbon's linked location           (still an explicit link)
 *   3. the appointment's free-text location    (a snapshot)
 *   4. the werkbon's free-text location
 *   5. the project's location
 *   6. the customer's own address
 *
 * Explicit links always beat free text.
 */
class EventLocationResolver
{
    /**
     * Every relation resolve() walks. Spread into with()/load() so the escalation
     * can't N+1, and so a new branch below only needs its relation adding here.
     * The prefix roots the paths somewhere other than the event itself ('events.').
     *
     * Both linkedLocation hops are missing on purpose: Event::$with and
     * ServiceOrder::$with already load them. Project is left unconstrained so it
     * survives being merged with a caller's own column-constrained with().
     */
    public static function relations(string $prefix = ''): array
    {
        return array_map(fn ($relation) => $prefix . $relation, [
            'serviceOrders.customer',
            'serviceOrders.project',
            'customers',
        ]);
    }

    public function resolve(Event $event): ?string
    {
        if ($event->location_id) {
            return $event->linkedLocation?->addressLine();
        }

        $order = $event->serviceOrders->first();

        if ($order?->location_id) {
            return $order->linkedLocation?->addressLine();
        }

        if (!empty($event->location)) {
            return $event->location;
        }

        return $this->inherited($event);
    }

    /**
     * Waar de speld hoort te staan: dezelfde escalatie als resolve(), maar dan
     * het eerste model op die ladder dat ook coördinaten draagt.
     *
     * Alleen een gekoppelde locatie en de klant hebben lat/lon; de sporten met
     * vrije tekst ertussen hebben die niet. Landt de escalatie op zo'n sport,
     * dan komt er geen speld — doorschuiven naar de klant zou hem neerzetten op
     * precies het adres dat die vrije tekst overruled.
     *
     * Op één plek is dat te streng: de planner vult het tekstveld van een
     * afspraak voor met het afgeleide adres en slaat het onveranderd terug op,
     * dus een gevulde regel is meestal een kopie en geen afwijking. Alleen een
     * regel die er echt anders uitziet houdt de afspraak van de kaart.
     */
    public function coordinates(Event $event): ?Model
    {
        if ($event->location_id) {
            return $this->withCoordinates($event->linkedLocation);
        }

        $order = $event->serviceOrders->first();

        if ($order?->location_id) {
            return $this->withCoordinates($order->linkedLocation);
        }

        if (!empty($event->location) && $this->deviatesFromInherited($event)) {
            return null;
        }

        if (!empty($order?->execution_location) || !empty($order?->project?->location)) {
            return null;
        }

        return $this->withCoordinates($event->primaryCustomer());
    }

    private function withCoordinates(?Model $place): ?Model
    {
        return $place && $place->lat !== null && $place->lon !== null ? $place : null;
    }

    /**
     * The same escalation with the appointment's own two rungs taken out: where
     * this appointment would happen if nobody had given it an address of its own.
     */
    public function inherited(Event $event): ?string
    {
        $order = $event->serviceOrders->first();

        if ($order?->location_id) {
            return $order->linkedLocation?->addressLine();
        }

        if (!empty($order?->execution_location)) {
            return $order->execution_location;
        }

        if (!empty($order?->project?->location)) {
            return $order->project->location;
        }

        return $this->customerAddress($event);
    }

    /**
     * Whether this appointment carries an address that the escalation would not
     * have produced by itself — a one-off, entered for this visit only.
     *
     * A filled `location` column is not enough to say that: the planner dialog
     * prefills its input with the resolved address and saves it back verbatim,
     * so an appointment can own a copy of the very address it inherits. Only a
     * value that actually differs deviates.
     */
    public function deviatesFromInherited(Event $event): bool
    {
        $own = $event->location_id
            ? $event->linkedLocation?->addressLine()
            : $event->location;

        if (empty($own)) {
            return false;
        }

        return $this->comparable($own) !== $this->comparable($this->inherited($event));
    }

    private function comparable(?string $address): string
    {
        return preg_replace('/\s+/', ' ', mb_strtolower(trim((string) $address)));
    }

    private function customerAddress(Event $event): ?string
    {
        $customer = $event->primaryCustomer();

        if (!$customer) {
            return null;
        }

        return AddressFormatter::format($customer->address, $customer->postal_code, $customer->city);
    }
}

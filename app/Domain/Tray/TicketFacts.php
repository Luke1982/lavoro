<?php

namespace App\Domain\Tray;

use App\Exceptions\Refusal;
use App\Models\Customer;
use App\Models\Ticket;
use Illuminate\Support\Collection;

/**
 * What the bakje needs to know about a storing: what to call it, which machine
 * it is on and whose machine that is.
 *
 * A storing has no customer of its own. It hangs off a machine, and a machine
 * inside another machine belongs to whoever owns the outer one.
 */
final class TicketFacts
{
    public static function title(Ticket $ticket): string
    {
        return $ticket->subject ?: 'Storing #' . $ticket->id;
    }

    public static function machine(Ticket $ticket): ?string
    {
        return $ticket->asset ? AssetFacts::title($ticket->asset) : null;
    }

    public static function customerId(Ticket $ticket): ?int
    {
        return $ticket->asset?->resolvedCustomerId();
    }

    public static function locationId(Ticket $ticket): ?int
    {
        return $ticket->asset?->rootAsset()->location_id;
    }

    /**
     * The one customer all of these storingen belong to, or a refusal that says
     * why there is none.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public static function sharedCustomer(Collection $tickets): Customer
    {
        $customer_ids = $tickets->map(function (Ticket $ticket) {
            $customer_id = self::customerId($ticket);

            if ($customer_id === null) {
                throw new Refusal('"' . self::title($ticket) . '" hangt niet aan een machine van een klant, '
                    . 'dus er is geen klant om een werkbon voor te maken.');
            }

            return $customer_id;
        })->unique();

        if ($customer_ids->count() > 1) {
            throw new Refusal('Deze storingen horen bij verschillende klanten. '
                . 'Pak ze los uit de stapel en plak ze per klant.');
        }

        return Customer::findOrFail($customer_ids->first());
    }

    /**
     * Where the werkbon is, when every storing is in the same place. Spread over
     * several, a guess would be worse than leaving it empty.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public static function sharedLocationId(Collection $tickets): ?int
    {
        $location_ids = $tickets->map(fn (Ticket $ticket) => self::locationId($ticket))->unique();

        return $location_ids->count() === 1 ? $location_ids->first() : null;
    }

    /**
     * What a new werkbon for these storingen says it is for.
     *
     * @param  Collection<int, Ticket>  $tickets
     */
    public static function serviceOrderDescription(Collection $tickets): string
    {
        $titles = $tickets->map(fn (Ticket $ticket) => self::title($ticket));

        $description = $titles->count() === 1
            ? $titles->first()
            : 'Storingen: ' . $titles->implode(', ');

        return mb_strimwidth($description, 0, 255, '…');
    }

    /**
     * @param  Collection<int, Ticket>  $tickets
     * @return array<int, array{title: string, subtitle: ?string}>
     */
    public static function listed(Collection $tickets): array
    {
        return $tickets->map(fn (Ticket $ticket) => [
            'title' => self::title($ticket),
            'subtitle' => self::machine($ticket),
        ])->values()->all();
    }
}

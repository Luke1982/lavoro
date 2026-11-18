<?php

namespace App\Domain\Tray\Kinds;

use App\Domain\Tray\Paste\TicketsOntoAsset;
use App\Domain\Tray\Paste\TicketsOntoPlanner;
use App\Domain\Tray\Paste\TicketsOntoProject;
use App\Domain\Tray\Paste\TicketsOntoServiceOrder;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

/**
 * Storingen on the bakje.
 *
 * A storing has no customer of its own. It hangs off a machine, and a machine
 * inside another machine belongs to whoever owns the outer one.
 */
final class TicketKind extends TrayKind
{
    public static function name(): string
    {
        return 'ticket';
    }

    public static function model(): string
    {
        return Ticket::class;
    }

    public static function one(): string
    {
        return 'storing';
    }

    public static function many(): string
    {
        return 'storingen';
    }

    public static function places(): array
    {
        return [
            'planner' => TicketsOntoPlanner::class,
            'serviceorder' => TicketsOntoServiceOrder::class,
            'asset' => TicketsOntoAsset::class,
            'project' => TicketsOntoProject::class,
        ];
    }

    public static function visibleTo(User $user): Builder
    {
        return Ticket::visibleTo($user)->with(['asset.product.brand', 'serviceOrder']);
    }

    /** @param  Ticket  $ticket */
    public static function title(Model $ticket): string
    {
        return $ticket->subject ?: 'Storing #' . $ticket->id;
    }

    /**
     * In quotes: a subject is whatever somebody typed, and reads as part of the
     * sentence without them.
     *
     * @param  Ticket  $ticket
     */
    public static function named(Model $ticket): string
    {
        return '"' . self::title($ticket) . '"';
    }

    /**
     * The machine it is on.
     *
     * @param  Ticket  $ticket
     */
    public static function subtitle(Model $ticket): ?string
    {
        return $ticket->asset ? AssetKind::title($ticket->asset) : null;
    }

    /** @param  Ticket  $ticket */
    public static function customerId(Model $ticket): ?int
    {
        return $ticket->asset?->resolvedCustomerId();
    }

    /** @param  Ticket  $ticket */
    public static function locationId(Model $ticket): ?int
    {
        return $ticket->asset?->resolvedLocationId();
    }

    /** @param  Ticket  $ticket */
    protected static function withoutCustomer(Model $ticket): string
    {
        return self::named($ticket) . ' hangt niet aan een machine van een klant, '
            . 'dus er is geen klant om een werkbon voor te maken.';
    }

    /** Only loose storingen go on it; the action leaves one that is already on a werkbon where it is. */
    protected static function serviceOrderContents(Collection $tickets): array
    {
        return ['ticket_ids' => $tickets->pluck('id')->all()];
    }

    protected static function serviceOrderDescription(Collection $tickets): string
    {
        return $tickets->count() === 1
            ? self::title($tickets->first())
            : 'Storingen: ' . $tickets->map(fn (Ticket $ticket) => self::title($ticket))->implode(', ');
    }

    /** @param  Ticket  $ticket */
    protected static function card(Model $ticket): array
    {
        return [
            'service_order_id' => $ticket->service_order_id,
            'service_order_number' => $ticket->serviceOrder?->number,
            'url' => '/tickets/' . $ticket->id,
        ];
    }
}

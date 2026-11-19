<?php

namespace App\Domain\Tray\Paste;

use App\Exceptions\Refusal;
use App\Models\Asset;
use App\Models\Customer;
use App\Models\Location;
use App\Models\MaintenanceContract;
use App\Models\Project;
use App\Models\ServiceOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/**
 * The record something was dropped on, as long as the person may open it. The
 * same place takes more than one kind of thing, so every paste onto a werkbon,
 * however different, starts from the same werkbon with the same refusals.
 */
final class DropTarget
{
    /** A werkbon that work can still be added to. */
    public static function openServiceOrder(Paste $paste): ServiceOrder
    {
        $order = ServiceOrder::visibleTo($paste->user)->find($paste->target['id'])
            ?? throw new Refusal('Deze werkbon kun je niet openen.');

        if ($order->is_closed) {
            throw new Refusal('Werkbon ' . $order->number . ' is al afgesloten.');
        }

        return $order;
    }

    public static function asset(Paste $paste): Asset
    {
        return Asset::visibleTo($paste->user)->with(['product.brand', 'product.productType'])->find($paste->target['id'])
            ?? throw new Refusal('Deze machine kun je niet openen.');
    }

    /** A running project to put a werkbon under for this customer: its own, or one that has none. */
    public static function projectFor(Paste $paste, Customer $customer): Project
    {
        $project = self::viewable($paste, Project::find($paste->target['id']), 'Dit project kun je niet openen.');

        if ($refusal = $project->refusesNewWork()) {
            throw new Refusal($refusal);
        }

        if ($project->customer_id && (int) $project->customer_id !== $customer->id) {
            throw new Refusal($paste->these('hoort', 'horen') . ' bij ' . $customer->name
                . ', het project bij een andere klant.');
        }

        return $project;
    }

    public static function contract(Paste $paste): MaintenanceContract
    {
        return self::viewable($paste, MaintenanceContract::find($paste->target['id']), 'Dit contract kun je niet openen.');
    }

    public static function customer(Paste $paste): Customer
    {
        return self::viewable($paste, Customer::find($paste->target['id']), 'Deze klant kun je niet openen.');
    }

    public static function location(Paste $paste): Location
    {
        return self::viewable($paste, Location::find($paste->target['id']), 'Deze locatie kun je niet openen.');
    }

    /**
     * @template TModel of Model
     *
     * @param  TModel|null  $record
     * @return TModel
     */
    private static function viewable(Paste $paste, ?Model $record, string $refusal): Model
    {
        if (!$record || Gate::forUser($paste->user)->denies('view', $record)) {
            throw new Refusal($refusal);
        }

        return $record;
    }
}

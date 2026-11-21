<?php

namespace App\Actions\Locations;

use App\Domain\Signals\Appointments\AppointmentLocationDeleted;
use App\Domain\Signals\Signals;
use App\Models\Asset;
use App\Models\Event;
use App\Models\Location;
use App\Models\Project;
use App\Models\Quote;
use App\Models\ServiceOrder;
use Illuminate\Support\Facades\DB;

/**
 * Removes a location, after moving what stands there to another location of the
 * same customer or to no location at all: its machines, werkbonnen, projects,
 * offertes and appointments. Nothing else points at a location.
 *
 * Every machine, werkbon, project and offerte is saved on its own, so each says on
 * its own timeline where it went (a machine on its customer's too), and a
 * contract priced by its machines' address reprices. A single query would do the
 * same in silence, and the database would clear the column of whatever was left
 * without a word.
 *
 * Appointments are moved by one query and each announced with
 * AppointmentLocationDeleted. Saving them would send the e-mails an
 * administrator hung on "afspraak gewijzigd", and nobody wants a mail because an
 * address was tidied up; the announcement still puts it on the appointment and
 * its werkbonnen, and sends the new address to Google.
 *
 * A busy location can hold more than a request's worth of facts, so it runs as a
 * batch.
 */
class DeleteLocationAction
{
    public function execute(Location $location, ?int $target_location_id): void
    {
        Signals::asBatch(fn () => DB::transaction(function () use ($location, $target_location_id) {
            $target = $target_location_id === null ? null : Location::find($target_location_id);
            $moved = ['location_id' => $target_location_id];

            $location->assets()->get()->each(fn (Asset $asset) => $asset->update($moved));
            $location->serviceOrders()->get()->each(fn (ServiceOrder $order) => $order->update($moved));
            $location->projects()->get()->each(fn (Project $project) => $project->update($moved));
            $location->quotes()->get()->each(fn (Quote $quote) => $quote->update($moved));

            $appointments = $location->events()->with('serviceOrders')->get();

            $location->events()->update($moved);

            $appointments->each(fn (Event $event) => Signals::dispatch(
                new AppointmentLocationDeleted($event, $location, $target),
            ));

            $location->delete();
        }));
    }
}

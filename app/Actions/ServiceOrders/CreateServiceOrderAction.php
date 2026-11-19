<?php

namespace App\Actions\ServiceOrders;

use App\Domain\Signals\Projects\ServiceOrderMovedBetweenProjects;
use App\Domain\Signals\ServiceOrders\TicketAttachedToOrder;
use App\Domain\Signals\Signals;
use App\Exceptions\Refusal;
use App\Models\Asset;
use App\Models\Project;
use App\Models\ServiceOrder;
use App\Models\Ticket;
use App\Support\AssetOwnership;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Opens a werkbon, with whatever machines and storingen belong on it.
 *
 * Creating one is never just the row: a machine put on it becomes a service job,
 * a storing hung off it stops being loose, and both of those are things the
 * timeline is expected to show afterwards. Doing that in a controller meant the
 * only way to open a werkbon correctly was to be a web request.
 *
 * All of it in one transaction, so a werkbon never exists holding half the work
 * that was asked for.
 */
class CreateServiceOrderAction
{
    public function execute(NewServiceOrder $order): ServiceOrder
    {
        $project = $order->project_id ? Project::findOrFail($order->project_id) : null;

        if ($refusal = $project?->refusesNewWork()) {
            throw new Refusal($refusal);
        }

        return DB::transaction(function () use ($order, $project) {
            $service_order = ServiceOrder::create([
                'customer_id' => $order->customer_id,
                'project_id' => $order->project_id,
                'location_id' => $order->location_id,
                'description' => $order->description,
                'type' => $order->type?->value,
            ]);

            if ($project) {
                Signals::dispatch(new ServiceOrderMovedBetweenProjects($service_order, null, $project));
            }

            $tickets = $this->attachTickets($service_order, $order->ticket_ids);
            $this->attachAssets($service_order, $order->asset_ids);
            $this->inferLocation($service_order, collect($order->asset_ids), $tickets->pluck('id'));

            return $service_order;
        });
    }

    /**
     * @param  array<int, int>  $ticket_ids
     * @return Collection<int, Ticket> the storingen that went onto the werkbon
     */
    private function attachTickets(ServiceOrder $service_order, array $ticket_ids): Collection
    {
        if ($ticket_ids === []) {
            return collect();
        }

        /** Only loose ones: a storing already on another werkbon is not free to take. */
        $tickets = Ticket::whereIn('id', $ticket_ids)
            ->whereNull('service_order_id')
            ->with(['asset.product.brand', 'asset.product.productType'])
            ->get();

        foreach ($tickets as $ticket) {
            $ticket->update(['service_order_id' => $service_order->id]);

            Signals::dispatch(new TicketAttachedToOrder($service_order, $ticket, $ticket->asset));
        }

        return $tickets;
    }

    /**
     * @param  array<int, int>  $asset_ids
     */
    private function attachAssets(ServiceOrder $service_order, array $asset_ids): void
    {
        app(AddServiceJobsAction::class)->execute($service_order, Asset::findMany($asset_ids));
    }

    /**
     * A werkbon whose machines all stand in one place is a werkbon for that place: the
     * machines picked, and the ones under the storingen that came along, each where its
     * root machine stands. Where they are spread over several, the customer's main address
     * among them, guessing would be worse than leaving it empty.
     *
     * @param  Collection<int, int>  $asset_ids
     * @param  Collection<int, int>  $ticket_ids
     */
    private function inferLocation(ServiceOrder $service_order, Collection $asset_ids, Collection $ticket_ids): void
    {
        if ($service_order->location_id) {
            return;
        }

        $places = AssetOwnership::forWork($asset_ids, $ticket_ids)->pluck('location_id')->unique();

        if ($places->count() === 1 && $places->first() !== null) {
            $service_order->update(['location_id' => $places->first()]);
        }
    }
}

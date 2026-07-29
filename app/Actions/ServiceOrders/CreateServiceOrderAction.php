<?php

namespace App\Actions\ServiceOrders;

use App\Domain\Signals\ServiceOrders\ServiceJobAdded;
use App\Domain\Signals\ServiceOrders\TicketAttachedToOrder;
use App\Domain\Signals\Signals;
use App\Enums\ServiceJobOutcomes;
use App\Models\Asset;
use App\Models\ServiceJob;
use App\Models\ServiceOrder;
use App\Models\Ticket;
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
        return DB::transaction(function () use ($order) {
            $service_order = ServiceOrder::create([
                'customer_id' => $order->customer_id,
                'project_id' => $order->project_id,
                'location_id' => $order->location_id,
                'description' => $order->description,
            ]);

            $this->attachTickets($service_order, $order->ticket_ids);
            $this->attachAssets($service_order, $order->asset_ids);
            $this->inferLocation($service_order, $order);

            return $service_order;
        });
    }

    /**
     * @param  array<int, int>  $ticket_ids
     */
    private function attachTickets(ServiceOrder $service_order, array $ticket_ids): void
    {
        if ($ticket_ids === []) {
            return;
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
    }

    /**
     * @param  array<int, int>  $asset_ids
     */
    private function attachAssets(ServiceOrder $service_order, array $asset_ids): void
    {
        foreach ($asset_ids as $asset_id) {
            $job = ServiceJob::create([
                'asset_id' => $asset_id,
                'service_order_id' => $service_order->id,
                'outcome' => ServiceJobOutcomes::nog_geen_uitkomst->value,
            ]);

            $asset = $job->asset()->with(['product.brand', 'product.productType'])->first();

            if ($asset) {
                Signals::dispatch(new ServiceJobAdded($service_order, $asset));
            }
        }
    }

    /**
     * A werkbon whose machines all stand in one place is a werkbon for that
     * place. Where they are spread over several, guessing would be worse than
     * leaving it empty.
     */
    private function inferLocation(ServiceOrder $service_order, NewServiceOrder $order): void
    {
        if ($service_order->location_id || $order->asset_ids === []) {
            return;
        }

        $locations = Asset::whereIn('id', $order->asset_ids)
            ->pluck('location_id')
            ->filter()
            ->unique();

        if ($locations->count() === 1) {
            $service_order->location_id = $locations->first();
            $service_order->save();
        }
    }
}

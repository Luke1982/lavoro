<?php

namespace App\Listeners\ServiceOrders;

use App\Domain\Signals\ServiceOrders\ServiceOrderCustomerChanged;

/**
 * A werkbon generated from a contract stops being that contract's work the moment
 * it belongs to someone else, so the link goes rather than leaving the old
 * customer's contract reporting an order billed elsewhere. The activity line keeps
 * the provenance.
 */
class UnlinkContractFromReassignedOrder
{
    public function handle(ServiceOrderCustomerChanged $signal): void
    {
        $service_order = $signal->serviceOrder();

        if ($service_order->maintenance_contract_id === null) {
            return;
        }

        $service_order->update(['maintenance_contract_id' => null]);

        $service_order->logActivity(
            'Losgekoppeld van contract door klantwijziging: '
                . ($signal->previous_contract_title ?? 'onbekend')
        );
    }
}

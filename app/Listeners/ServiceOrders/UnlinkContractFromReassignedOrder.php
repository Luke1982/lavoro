<?php

namespace App\Listeners\ServiceOrders;

use App\Domain\Signals\ServiceOrders\ServiceOrderCustomerChanged;
use App\Domain\Signals\ServiceOrders\ServiceOrderUnlinkedFromContract;
use App\Domain\Signals\Signals;

/**
 * A werkbon generated from a contract stops being that contract's work the moment
 * it belongs to someone else, so the link goes rather than leaving the old
 * customer's contract reporting an order billed elsewhere. The signal it raises
 * keeps the provenance, on the werkbon, the contract and that contract's customer.
 */
class UnlinkContractFromReassignedOrder
{
    public function handle(ServiceOrderCustomerChanged $signal): void
    {
        $service_order = $signal->serviceOrder();

        if ($service_order->maintenance_contract_id === null) {
            return;
        }

        $contract = $service_order->maintenanceContract()->first();

        $service_order->update(['maintenance_contract_id' => null]);
        $service_order->setRelation('maintenanceContract', null);

        Signals::dispatch(new ServiceOrderUnlinkedFromContract(
            $service_order,
            $contract,
            $contract?->display_title,
            'door klantwijziging',
        ));
    }
}

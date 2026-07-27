<?php

namespace App\Listeners\ServiceOrders;

use App\Domain\Signals\ServiceOrders\ServiceOrderInvoiceRecorded;
use App\Models\ServiceOrderStage;

/**
 * Recording an invoice number moves the werkbon into the invoiced stage. This
 * rule lives here so the code that stores the number does not need to know it,
 * and so any other route to an invoice number gets the same behaviour.
 */
class AdvanceOrderToInvoicedStage
{
    public function handle(ServiceOrderInvoiceRecorded $signal): void
    {
        $invoiced_stage = ServiceOrderStage::where('is_invoiced_state', true)->first();

        if (!$invoiced_stage) {
            return;
        }

        $signal->serviceOrder()->moveToStage($invoiced_stage, 'door extern factuurnummer');
    }
}

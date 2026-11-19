<?php

namespace App\Listeners\Integrations;

use App\Domain\Integrations\DocumentForm;
use App\Domain\Signals\Integrations\ServiceOrderSentToAdministration;
use App\Domain\Signals\ServiceOrders\ServiceOrderInvoiceRecorded;
use App\Domain\Signals\Signals;

/**
 * A werkbon that became a final invoice there has that invoice's number: it
 * goes where someone would otherwise have typed it, and moving the werkbon to
 * the invoiced stage follows from that as it always does. A number someone
 * already entered is left alone.
 */
class RecordNumberOfFinalInvoice
{
    public function handle(ServiceOrderSentToAdministration $signal): void
    {
        $order = $signal->service_order;

        if ($signal->form !== DocumentForm::FinalInvoice || !filled($signal->number) || filled($order->external_invoice_no)) {
            return;
        }

        $order->external_invoice_no = $signal->number;
        $order->save();

        Signals::dispatch(new ServiceOrderInvoiceRecorded($order, $signal->number));
    }
}

<?php

namespace App\Services;

use App\Exceptions\Refusal;
use App\Models\Central\Invoice;
use App\Models\Central\PendingCharge;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Starting a year over: every invoice numbered in it is deleted and the next
 * one is number 1 again. The nuclear option, for a year of trial runs;
 * whatever of it reached a customer, the bank or e-Boekhouden has to be put
 * right there by hand.
 *
 * What those invoices settled -- topped up credit, settlements -- is
 * outstanding again, so nothing a customer owes is lost with them, and the
 * settlements over their periods are worked out again as for periods that
 * were never invoiced.
 */
class YearRestart
{
    /** @return array<int, string> The numbers that were deleted. */
    public function restart(int $year): array
    {
        $numbers = new InvoiceNumbers;

        return DB::connection('central')->transaction(function () use ($numbers, $year) {
            $numbers->startOver($year);

            $invoices = $numbers->ofYear($year)->orderBy('id')->get();
            $ids = $invoices->modelKeys();

            if ($across = $this->creditAcrossTheYear($invoices)) {
                throw new Refusal("Creditfactuur {$across->number} hoort bij een factuur uit een ander jaar."
                    . ' Een creditering kan niet half weg; begin het jaar daarom niet opnieuw.');
            }

            PendingCharge::on('central')->whereIn('invoice_id', $ids)->update(['invoice_id' => null]);

            /** Credit notes first: they point at the invoice they cancel. */
            Invoice::on('central')->whereIn('id', $ids)->whereNotNull('credits_invoice_id')->delete();
            Invoice::on('central')->whereIn('id', $ids)->delete();

            $periods = $invoices->whereNull('credits_invoice_id')->groupBy('tenant_id')
                ->map(fn (Collection $own) => $own->map(fn (Invoice $invoice) => $invoice->period_start->toDateString())->unique());

            foreach (Tenant::on('central')->whereIn('id', $periods->keys())->get() as $tenant) {
                (new Invoicer($tenant))->resettlePeriods($periods[$tenant->id]);
            }

            return $invoices->pluck('number')->all();
        });
    }

    /**
     * A credit note and the invoice it cancels go together or not at all.
     * Across the turn of the year one half would stay: a credit note in this
     * year for last year's invoice would bring that invoice back as if it was
     * never credited.
     */
    private function creditAcrossTheYear(Collection $invoices): ?Invoice
    {
        $ids = $invoices->modelKeys();

        return $invoices->first(fn (Invoice $invoice) => $invoice->credits_invoice_id
                && !in_array($invoice->credits_invoice_id, $ids))
            ?? Invoice::on('central')->whereIn('credits_invoice_id', $ids)->whereNotIn('id', $ids)->first();
    }
}

<?php

namespace App\Services\EBoekhouden;

use App\Exceptions\Refusal;
use App\Models\Central\Invoice;
use App\Models\Tenant;
use App\Support\Money;

/**
 * Books an invoice from the admin panel in e-Boekhouden as a sent invoice: on
 * the debtors account, against revenue, per line.
 *
 * By hand and not on creation. An invoice can still be withdrawn until it has
 * left the building, and one that is already in the bookkeeping has left it.
 */
class InvoiceBooker
{
    /**
     * e-Boekhouden's VAT codes for sales. Only the rate that is actually
     * charged; an unknown rate is refused rather than booked under a code that
     * happens to be close.
     */
    private const VAT_CODES = [21 => 'HOOG_VERK_21'];

    public function __construct(private EBoekhoudenClient $client) {}

    public function book(Invoice $invoice, Tenant $tenant): Invoice
    {
        if ($invoice->booked_at) {
            throw new Refusal("{$invoice->number} staat al in e-Boekhouden.");
        }

        $code = $tenant->eboekhouden_relation_code
            ?: throw new Refusal("Koppel {$tenant->name} eerst aan een relatie in e-Boekhouden; dat kan op het abonnementsscherm.");

        try {
            /**
             * Asked first, so a second click -- or a booking that did arrive
             * while the answer got lost -- does not book the invoice twice.
             */
            $number = $this->client->invoiceExists($invoice->number)
                ? null
                : $this->client->addInvoice($this->booking($invoice->loadMissing(['lines', 'credits']), $code));
        } catch (Refusal $e) {
            $invoice->forceFill(['booking_error' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw $e;
        }

        $invoice->forceFill([
            'booked_at' => now(),
            'booking_number' => $number,
            'booking_error' => null,
        ])->save();

        return $invoice;
    }

    /**
     * The booking, with the VAT per line adding up to exactly the VAT on the
     * invoice. That is computed over the total, so rounding per line can be a
     * cent off; the cent goes to the largest line instead of making the
     * bookkeeping disagree with the invoice the customer has.
     *
     * The yearly discount is not a line on the invoice but an amount under it;
     * here it becomes a line of its own, or revenue would be booked too high.
     */
    public function booking(Invoice $invoice, string $relation_code): array
    {
        $percent = (int) $invoice->vat_percent;
        $vat_code = self::VAT_CODES[$percent]
            ?? throw new Refusal("Voor {$percent}% btw is geen btw-code van e-Boekhouden ingesteld.");

        $amounts = $invoice->lines->pluck('amount_cents')->map(fn ($cents) => (int) $cents)->all();

        if ($invoice->discount_cents) {
            $amounts[] = -(int) $invoice->discount_cents;
        }

        $amounts = array_values(array_filter($amounts));
        $vat = array_map(fn (int $cents) => (int) round($cents * $percent / 100), $amounts);

        if ($amounts) {
            $largest = collect($amounts)->map(fn (int $cents) => abs($cents))->sortDesc()->keys()->first();
            $vat[$largest] += (int) $invoice->vat_cents - array_sum($vat);
        }

        return [
            'date' => $invoice->issued_on->format('Y-m-d'),
            'relation_code' => $relation_code,
            'number' => $invoice->number,
            'description' => $this->description($invoice),
            'payment_days' => $invoice->due_on ? (int) $invoice->issued_on->diffInDays($invoice->due_on) : 0,
            'lines' => array_map(fn (int $cents, int $tax) => [
                'excl' => Money::machine($cents),
                'vat' => Money::machine($tax),
                'incl' => Money::machine($cents + $tax),
                'vat_code' => $vat_code,
                'vat_percent' => number_format($percent, 2, '.', ''),
            ], $amounts, $vat),
        ];
    }

    private function description(Invoice $invoice): string
    {
        if ($invoice->credits) {
            return 'Creditfactuur voor ' . $invoice->credits->number;
        }

        return ($invoice->gross_cents < 0 ? 'Creditfactuur Lavoro ' : 'Lavoro ')
            . $invoice->period_start->format('d-m-Y') . ' t/m ' . $invoice->period_end->format('d-m-Y');
    }
}

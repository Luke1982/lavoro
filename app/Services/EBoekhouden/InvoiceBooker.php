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
 * By hand and not on creation: once it is in the bookkeeping an invoice has
 * left the building (Invoice::hasLeftTheBuilding), and the way back is a
 * credit note.
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

        $booking = $this->booking($invoice->loadMissing(['lines', 'credits']), $code);

        /**
         * Asked first: a booking that did arrive while the answer got lost is
         * not booked twice. That is the only way this invoice can already be
         * there -- after an attempt that failed, under its own relation.
         * Anything else with its number is a different invoice that had it
         * before the year was started over, and has to go in e-Boekhouden
         * first.
         */
        $booked = $this->client->bookedRelations($invoice->number);
        $arrived = $invoice->booking_error !== null && in_array($code, $booked, true);

        if ($booked && !$arrived) {
            throw new Refusal("Factuurnummer {$invoice->number} staat in e-Boekhouden al op relatie "
                . implode(', ', array_map(fn (string $other) => $other ?: '(geen)', $booked))
                . ', maar deze factuur is daar nooit heen gestuurd: het is een andere factuur met hetzelfde nummer.'
                . ' Haal die boeking daar weg en boek opnieuw.');
        }

        $invoice->forceFill([
            'booked_at' => now(),
            'booking_number' => $arrived ? null : $this->send($invoice, $booking),
            'booking_error' => null,
        ])->save();

        return $invoice;
    }

    /**
     * Sends the booking. A failure stays with the invoice: whether it reached
     * e-Boekhouden is not certain when the answer got lost, so from then on it
     * counts as gone out (Invoice::hasLeftTheBuilding). Everything before this
     * -- working the booking out, looking it up -- sends nothing and is not
     * recorded.
     */
    private function send(Invoice $invoice, array $booking): string
    {
        try {
            return $this->client->addInvoice($booking);
        } catch (Refusal $e) {
            $invoice->forceFill(['booking_error' => mb_substr($e->getMessage(), 0, 255)])->save();

            throw $e;
        }
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

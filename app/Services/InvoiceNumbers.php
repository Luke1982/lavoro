<?php

namespace App\Services;

use App\Exceptions\Refusal;
use App\Models\Central\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The invoice number series: continuous per year across all customers rather
 * than per customer, because the bookkeeping wants one series.
 */
class InvoiceNumbers
{
    public function prefix(int $year): string
    {
        return $year . '-LVR-';
    }

    /**
     * The next number, to be called inside the transaction that stores the
     * invoice.
     *
     * From a counter that only goes up, and not from the highest number in the
     * table. That highest number came free again as soon as an invoice went --
     * with a removed customer their invoices went along -- and the next invoice
     * took it. Two different invoices with the same number is exactly what a
     * continuous series must never produce, and the tax office reads that series
     * as proof that nothing was left out.
     *
     * The row is locked by the increment until the transaction commits, so two
     * invoices at the same moment get two numbers.
     */
    public function next(CarbonImmutable $on): string
    {
        $year = (int) $on->format('Y');
        $counter = DB::connection('central')->table('invoice_numbers');

        $counter->insertOrIgnore(['year' => $year, 'last_number' => 0]);

        $last = (int) ($counter->where('year', $year)->lockForUpdate()->value('last_number') ?? 0);

        /**
         * The highest number that is actually there counts too. A row written
         * around this service -- an import, a repair by hand -- does not move
         * the counter, and handing out a number that is already on an invoice
         * is worse than skipping one.
         */
        $next = max($last, $this->highest($year)) + 1;

        $counter->where('year', $year)->update(['last_number' => $next]);

        return $this->prefix($year) . $next;
    }

    /** The last number the counter handed out this year. */
    public function counter(int $year): int
    {
        return (int) (DB::connection('central')->table('invoice_numbers')
            ->where('year', $year)->value('last_number') ?? 0);
    }

    /** The highest number of the year that is still on an invoice. */
    public function highest(int $year): int
    {
        $prefix = $this->prefix($year);

        return (int) str_replace($prefix, '', (string) Invoice::on('central')
            ->where('number', 'like', $prefix . '%')
            ->orderByRaw('CAST(REPLACE(number, ?, "") AS UNSIGNED) DESC', [$prefix])
            ->value('number'));
    }

    /**
     * Sets the counter back to the highest number that is still on an invoice,
     * so the numbers of withdrawn invoices are handed out again.
     *
     * Never lower than that: the invoices that remain keep their number. And
     * only withdrawn invoices free a number, which is only possible before they
     * were mailed or collected -- what the customer has, stays spent.
     *
     * @return array{from: int, to: int}
     */
    public function reset(int $year): array
    {
        return DB::connection('central')->transaction(function () use ($year) {
            $counter = DB::connection('central')->table('invoice_numbers');
            $from = (int) ($counter->where('year', $year)->lockForUpdate()->value('last_number') ?? 0);
            $to = $this->highest($year);

            if ($from <= $to) {
                throw new Refusal('De nummering van ' . $year . ' sluit al aan op de laatste factuur'
                    . ($to ? ' (' . $this->prefix($year) . $to . ')' : '') . '; er valt niets terug te zetten.');
            }

            $counter->where('year', $year)->update(['last_number' => $to]);

            return ['from' => $from, 'to' => $to];
        });
    }
}

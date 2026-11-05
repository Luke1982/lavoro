<?php

namespace App\Services;

use App\Exceptions\Refusal;
use App\Models\Central\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder as InvoiceQuery;
use Illuminate\Database\Query\Builder;
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
     * The row is locked until the transaction commits, so two invoices at the
     * same moment get two numbers.
     */
    public function next(CarbonImmutable $on): string
    {
        $year = (int) $on->format('Y');
        $last = $this->lock($year);

        /**
         * The highest number that is actually there counts too. A row written
         * around this service -- an import, a repair by hand -- does not move
         * the counter, and handing out a number that is already on an invoice
         * is worse than skipping one.
         */
        $next = max($last, $this->highest($year)) + 1;

        $this->counterRow($year)->update(['last_number' => $next]);

        return $this->prefix($year) . $next;
    }

    /**
     * Whether this invoice carries the last number in the system: no invoice
     * was made after it, for any customer, and none in its year has a higher
     * number. Only that one can go without leaving a gap.
     */
    public function isNewest(Invoice $invoice): bool
    {
        [$year, $sequence] = $this->parse($invoice->number);

        return $sequence !== null
            && !Invoice::on('central')->where('id', '>', $invoice->id)->exists()
            && $this->highest($year) === $sequence;
    }

    /**
     * Hands the number of the newest invoice back as it is deleted, so the
     * next invoice gets it and the series stays without gaps. To be called in
     * the transaction that deletes it: the counter stays locked until then,
     * and nothing is numbered in between.
     */
    public function giveBack(Invoice $invoice): void
    {
        [$year, $sequence] = $this->parse($invoice->number);
        $counter = $this->lock($year);

        if (!$this->isNewest($invoice)) {
            throw new Refusal("Factuur {$invoice->number} is niet meer de laatste factuur.");
        }

        $this->counterRow($year)->update(['last_number' => min($counter, $sequence - 1)]);
    }

    /** The year whose series the invoice's number is from. */
    public function yearOf(Invoice $invoice): int
    {
        return $this->parse($invoice->number)[0];
    }

    /** The number next() would hand out now. */
    public function upcoming(int $year): string
    {
        return $this->prefix($year) . (max($this->counter($year), $this->highest($year)) + 1);
    }

    /** The invoices numbered in a year, credit notes included. */
    public function ofYear(int $year): InvoiceQuery
    {
        return Invoice::on('central')->where('number', 'like', $this->prefix($year) . '%');
    }

    /**
     * Sets the year's counter back to nothing handed out, and keeps it locked:
     * to be called in the transaction that deletes the year's invoices
     * (YearRestart), so nothing is numbered in between.
     */
    public function startOver(int $year): void
    {
        $this->lock($year);
        $this->counterRow($year)->update(['last_number' => 0]);
    }

    /** The last number the counter handed out this year. */
    public function counter(int $year): int
    {
        return (int) $this->counterRow($year)->value('last_number');
    }

    /**
     * Locks the year's counter until the transaction commits, and returns it.
     * Whatever changes the series takes this first, so it reads the series as
     * it is and nothing is numbered in between.
     *
     * Locked before anything is written: with the row already there -- every
     * invoice but the year's first -- an ignored insert still takes a shared
     * lock, and two of those waiting for the exclusive one is a deadlock.
     */
    public function lock(int $year): int
    {
        $last = $this->counterRow($year)->lockForUpdate()->value('last_number');

        if ($last === null) {
            DB::connection('central')->table('invoice_numbers')->insertOrIgnore(['year' => $year, 'last_number' => 0]);
            $last = $this->counterRow($year)->lockForUpdate()->value('last_number');
        }

        return (int) $last;
    }

    /** The highest number of the year that is still on an invoice. */
    public function highest(int $year): int
    {
        $prefix = $this->prefix($year);

        return (int) str_replace($prefix, '', (string) $this->ofYear($year)
            ->orderByRaw('CAST(REPLACE(number, ?, "") AS UNSIGNED) DESC', [$prefix])
            ->value('number'));
    }

    /**
     * Sets the counter back to the highest number that is still on an invoice,
     * so the numbers of withdrawn invoices are handed out again. Deleting gives
     * a number back by itself now (giveBack); this closes the gaps that
     * deleting under the old rule left at the end of a year.
     *
     * Never lower than that: the invoices that remain keep their number. And
     * only withdrawn invoices free a number, which is only possible before they
     * left the building -- what the customer or the bookkeeping may have,
     * stays spent.
     *
     * @return array{from: int, to: int}
     */
    public function reset(int $year): array
    {
        return DB::connection('central')->transaction(function () use ($year) {
            $from = $this->lock($year);
            $to = $this->highest($year);

            if ($from <= $to) {
                throw new Refusal('De nummering van ' . $year . ' sluit al aan op de laatste factuur'
                    . ($to ? ' (' . $this->prefix($year) . $to . ')' : '') . '; er valt niets terug te zetten.');
            }

            $this->counterRow($year)->update(['last_number' => $to]);

            return ['from' => $from, 'to' => $to];
        });
    }

    private function counterRow(int $year): Builder
    {
        return DB::connection('central')->table('invoice_numbers')->where('year', $year);
    }

    /** @return array{int, ?int} The year and the number within it; no number when it is not one of ours. */
    private function parse(string $number): array
    {
        [$year, $sequence] = array_pad(explode('-LVR-', $number), 2, null);

        return [(int) $year, ctype_digit((string) $sequence) ? (int) $sequence : null];
    }
}

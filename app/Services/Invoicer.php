<?php

namespace App\Services;

use App\Exceptions\Refusal;
use App\Models\Central\Invoice;
use App\Models\Central\IssuerSetting;
use App\Models\Central\PendingCharge;
use App\Models\Central\PricingSetting;
use App\Models\Tenant;
use App\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class Invoicer
{
    public function __construct(private Tenant $tenant) {}

    public function isYearly(): bool
    {
        return $this->tenant->billing_period === 'yearly';
    }

    /**
     * The day the periods are counted from: the start of the current term, but
     * never before the subscription itself started. A term switch on a
     * customer that has not started yet anchors the term on the start date;
     * moving that start date later used to leave the anchor where it was, and
     * the customer got periods -- and settlements over them -- from before
     * they ever started.
     *
     * Without a start date there is nothing to invoice; this fallback only
     * keeps the date arithmetic intact for screens that ask anyway.
     * subscriptionIsDue() refuses such a customer separately.
     */
    private function startedOn(): CarbonImmutable
    {
        return collect([$this->tenant->subscription_started_on, $this->tenant->billing_period_started_on])
            ->filter()
            ->map(fn (string $date) => CarbonImmutable::parse($date))
            ->max() ?? CarbonImmutable::now()->startOfMonth();
    }

    /**
     * The day a new billing term should start on.
     *
     * The first period that has not been invoiced yet. If the current one is
     * already paid, the new term starts after it: otherwise a customer moving
     * from monthly to yearly in March would be charged for a year starting in a
     * month they already paid for.
     */
    public function termStartsOn(?CarbonImmutable $on = null): CarbonImmutable
    {
        [$start, $end] = $this->periodFor($on ?? CarbonImmutable::now());

        return $this->subscriptionWasInvoicedFor($start) ? $end->addDay() : $start;
    }

    /** A month or a year, in months. The same number everywhere. */
    private function monthsPerPeriod(): int
    {
        return $this->isYearly() ? 12 : 1;
    }

    /**
     * The period a date falls in, counted from the start date. That keeps a
     * customer who started on the 12th on the 12th as their invoice day, in
     * February too.
     *
     * Every period is computed from the original start date rather than added
     * up step by step, and without overflowing into the next month. Someone who
     * started on the 31st drifted forever otherwise: 31 January plus a month is
     * 3 March, and from then on the invoice day was the 3rd. Now it is only
     * shortened in short months -- 31 January, 28 February, 31 March -- and the
     * customer keeps their own day.
     */
    public function periodFor(CarbonImmutable $on): array
    {
        $start = $this->startedOn();
        $step = $this->monthsPerPeriod();
        $periods = 0;

        /**
         * Counted rather than derived from the number of months in between:
         * those two disagree around the end of the month. From 31 January to
         * 28 February is zero whole months, while 28 February most certainly
         * starts the next period.
         */
        while ($start->addMonthsNoOverflow(($periods + 1) * $step)->lessThanOrEqualTo($on)) {
            $periods++;
        }

        return [
            $start->addMonthsNoOverflow($periods * $step),
            $start->addMonthsNoOverflow(($periods + 1) * $step)->subDay(),
        ];
    }

    /** @return array<int, array{description: string, kind: string, amount_cents: int}> */
    public function lines(?CarbonImmutable $on = null): array
    {
        $on = $on ?? CarbonImmutable::now();

        return [...$this->subscriptionLines($on), ...$this->chargeLines($this->charges())];
    }

    /**
     * This period's subscription, itemised, and empty as soon as that period
     * has been charged: without that condition an interim invoice for topped up
     * credit adds the whole month a second time.
     *
     * Per line and not as one amount: the invoice should say what is being paid
     * for. The period is only printed behind the first line; it applies to the
     * whole block and is in the header as well.
     *
     * @return array<int, array{description: string, kind: string, amount_cents: int}>
     */
    public function subscriptionLines(CarbonImmutable $on): array
    {
        if (!$this->subscriptionIsDue($on)) {
            return [];
        }

        [$start, $end] = $this->periodFor($on);

        $months = $this->monthsPerPeriod();
        $lines = [];

        foreach ((new TenantSubscription($this->tenant))->breakdown() as $index => $line) {
            /**
             * What did not run for the whole period is charged pro rata:
             * someone adding a module on the seventh pays for the days left in
             * the month, and someone cancelling halfway pays up to and
             * including the day it stops.
             */
            $window = $this->activeWindow($line, $start, $end);
            $from = ($window['from'] ?? $start)->format('d-m-Y');
            $to = ($window['to'] ?? $end)->format('d-m-Y');
            $part = $window ? sprintf(' (%d van %d dagen)', $window['days'], $window['total']) : '';

            $description = $index === 0
                ? $line['description'] . ' ' . $from . ' t/m ' . $to . ($months > 1 ? ' (12 maanden)' : '') . $part
                : $line['description'] . ($window ? ' ' . $from . ' t/m ' . $to . $part : '');

            /**
             * If a line has an agreed price, the normal price belongs next to
             * it: in a year or two nobody remembers why a different amount was
             * there, and the customer should see it was an agreement and not a
             * mistake.
             *
             * That is the catalogue price, also when only part of the period is
             * charged. Pro-rating it produced an amount that exists nowhere --
             * 'normaal EUR 18,00' for a module that plainly costs EUR 22,50 --
             * and how many days it covers is already at the front of the line.
             */
            if (isset($line['regular_cents'])) {
                $description .= ', normaal € ' . Money::human($line['regular_cents'] * $months)
                    . ', speciale prijsafspraak';
            }

            /**
             * Pro rata over the amount for the whole period, not over the
             * monthly amount before multiplying: rounding first and then
             * multiplying by twelve turns half a cent into six.
             */
            $amount = $line['amount_cents'] * $months;

            $lines[] = [
                'description' => $description,
                'kind' => $line['kind'],
                'amount_cents' => $window
                    ? (int) round($amount * $window['days'] / $window['total'])
                    : $amount,
            ];
        }

        return $lines;
    }

    /**
     * The day the line was switched on, or nothing when that is not recorded.
     * For a bundle it is the last of the modules in it: only then was the
     * bundle complete and charged as a bundle.
     *
     * @param  array<string, mixed>  $line
     */
    private function startedOnFor(array $line): ?CarbonImmutable
    {
        $dates = collect($line['module_keys'] ?? [])
            ->map(fn (string $key) => ($this->tenant->module_started_on ?? [])[$key] ?? null)
            ->filter()
            ->map(fn (string $date) => CarbonImmutable::parse($date));

        return $dates->count() === count($line['module_keys'] ?? [])
            ? $dates->max()
            : null;
    }

    /** The last day of the subscription, if it has been cancelled. */
    private function endsOn(): ?CarbonImmutable
    {
        return $this->tenant->subscription_ends_on
            ? CarbonImmutable::parse($this->tenant->subscription_ends_on)
            : null;
    }

    /**
     * The part of this period the line counts for, when that is not the whole
     * period: from the day it was switched on up to and including the day the
     * subscription stops. Nothing as soon as it covers the whole period.
     *
     * @param  array<string, mixed>  $line
     * @return array{from: CarbonImmutable, to: CarbonImmutable, days: int, total: int}|null
     */
    private function activeWindow(array $line, CarbonImmutable $start, CarbonImmutable $end): ?array
    {
        $started = $this->startedOnFor($line);
        $from = $started && $started->greaterThan($start) ? $started : $start;

        $ends = $this->endsOn();
        $to = $ends && $ends->lessThan($end) ? $ends : $end;

        $total = (int) $start->diffInDays($end->addDay());
        $days = (int) max(0, $from->startOfDay()->diffInDays($to->addDay()));

        return $days < $total ? compact('from', 'to', 'days', 'total') : null;
    }

    /** The one-off charges raised since the previous invoice; none for the demo. */
    private function charges(): Collection
    {
        return $this->tenant->isDemo() ? new Collection : $this->pendingCharges();
    }

    /** @return array<int, array{description: string, kind: string, amount_cents: int}> */
    private function chargeLines(Collection $charges): array
    {
        return $charges->map(fn (PendingCharge $charge) => [
            'description' => $charge->description,
            'kind' => $charge->kind,
            'amount_cents' => (int) $charge->amount_cents,
        ])->all();
    }

    /**
     * Has the subscription for the current period already been charged?
     *
     * It looks for an invoice for this period that actually contains the
     * subscription, not merely for the existence of an invoice. An interim
     * invoice for topped up credit falls in the same period; that one must not
     * push the monthly invoice aside.
     */
    public function subscriptionIsDue(?CarbonImmutable $on = null): bool
    {
        $on = $on ?? CarbonImmutable::now();

        /**
         * The demo is rebuilt every night with a fresh start date. Invoicing it
         * would spend a real number from the series the tax office reads, every
         * single night.
         */
        if ($this->tenant->isDemo() || !$this->tenant->subscription_started_on) {
            return false;
        }

        [$start] = $this->periodFor($on);

        if ($start->startOfDay()->greaterThan($on->startOfDay())) {
            return false;
        }

        /** Cancelled before this period began: there is nothing left to send. */
        if ($this->endsOn()?->lessThan($start)) {
            return false;
        }

        return !$this->subscriptionWasInvoicedFor($start);
    }

    /**
     * Is this period's subscription already on an invoice? It looks for the
     * subscription line and not for the existence of an invoice: an interim
     * invoice for topped up credit falls in the same period.
     *
     * It looks for an invoice whose period contains the first day of this
     * period, not for one that starts exactly on that day. Should the division
     * ever shift -- a corrected start date, or the repair of the month step --
     * a search on the exact day would find nothing and days that were already
     * paid for would be charged a second time.
     */
    private function subscriptionWasInvoicedFor(CarbonImmutable $start): bool
    {
        /**
         * A credited invoice no longer counts, and neither does the credit
         * note that cancelled it: the period is open again for the invoice that
         * should have been sent.
         */
        return Invoice::on('central')
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('credits_invoice_id')
            ->whereDoesntHave('creditedBy')
            ->whereDate('period_start', '<=', $start->toDateString())
            ->whereDate('period_end', '>=', $start->toDateString())
            ->whereHas('lines', fn ($query) => $query->where('kind', 'subscription'))
            ->exists();
    }

    /**
     * Is there anything to invoice? A new period's subscription, or one-off
     * charges raised since the previous invoice -- a package change, topped up
     * AI credit. With neither, invoicing produces an empty invoice and that
     * should not be possible.
     */
    public function isDue(?CarbonImmutable $on = null): bool
    {
        /**
         * The same question issue() asks: is there a line to put on an invoice.
         * It used to be answered from the two sources separately, and an
         * outstanding charge of zero euro then lit up the button for an invoice
         * that issue() refuses -- "there is nothing to invoice" on a screen that
         * had just offered to make one.
         */
        return $this->hasStarted($on ?? CarbonImmutable::now()) && $this->preview($on)['lines'] !== [];
    }

    /**
     * Nothing is invoiced before the start, not even a charge that is already
     * outstanding: it goes on the first invoice. A lone settlement was once
     * invoiced on its own that way, days before the customer started. Without
     * a start date the customer is never invoiced, as the panel says.
     */
    private function hasStarted(CarbonImmutable $on): bool
    {
        return $this->tenant->subscription_started_on
            && CarbonImmutable::parse($this->tenant->subscription_started_on)->lessThanOrEqualTo($on->endOfDay());
    }

    /**
     * The day the next invoice can be made: today if there is something to
     * invoice, otherwise the first day of the next period. For a customer who
     * has not started that is the start date, also with a charge outstanding.
     * Nothing once a cancelled subscription has been invoiced up to its last
     * day.
     *
     * The screens used to show what could be invoiced today -- nothing, for a
     * customer starting next week -- and the day after the current period,
     * which for that customer is a month too late.
     */
    public function nextInvoiceOn(?CarbonImmutable $on = null): ?CarbonImmutable
    {
        $on = ($on ?? CarbonImmutable::now())->startOfDay();

        if ($this->tenant->isDemo() || !$this->tenant->subscription_started_on) {
            return null;
        }

        if ($this->isDue($on)) {
            return $on;
        }

        [$start, $end] = $this->periodFor($on);
        $next = $start->greaterThan($on) ? $start : $end->addDay();

        return $this->endsOn()?->lessThan($next) ? null : $next;
    }

    /**
     * Does this produce a credit note? One where money goes back instead of
     * out: after a cancellation halfway through a month that was already paid,
     * for instance.
     */
    public function isCreditNote(?CarbonImmutable $on = null): bool
    {
        return $this->preview($on)['total_cents'] < 0;
    }

    /**
     * Periods that are over and for which a subscription was never charged.
     *
     * Only one period is ever invoiced: today's. Skip a month -- the cron is
     * down, the button is not pressed -- and that month never comes back. The
     * customer keeps working and a month of revenue is quietly lost. This makes
     * visible which ones.
     *
     * Nothing is invoiced retroactively on its own: a customer with a start
     * date far in the past would get a stack of invoices in one go, and today's
     * outstanding charges would end up on an old invoice. Someone should put
     * that right by hand.
     *
     * @return array<int, array{start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function unbilledPeriods(?CarbonImmutable $on = null): array
    {
        if (!$this->tenant->subscription_started_on) {
            return [];
        }

        $on = $on ?? CarbonImmutable::now();
        [$current] = $this->periodFor($on);

        /**
         * Counted from the start date and not from the anchor of the current
         * term: moving someone from monthly to yearly shifts that anchor to
         * today and the months before it would drop out of view -- precisely
         * the months this is about.
         */
        $start = CarbonImmutable::parse($this->tenant->subscription_started_on);
        $step = $this->monthsPerPeriod();
        $missed = [];

        /** A limit, so a start date from 2015 does not ask a hundred questions here. */
        for ($index = 0; $index < 120; $index++) {
            $from = $start->addMonthsNoOverflow($index * $step);

            if (!$from->lessThan($current) || $this->endsOn()?->lessThan($from)) {
                break;
            }

            if (!$this->subscriptionWasInvoicedFor($from)) {
                $missed[] = [
                    'start' => $from,
                    'end' => $start->addMonthsNoOverflow(($index + 1) * $step)->subDay(),
                ];
            }
        }

        return $missed;
    }

    public function pendingCharges(): Collection
    {
        return PendingCharge::on('central')
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('invoice_id')
            ->orderBy('id')
            ->get();
    }

    private function yearlyDiscountCents(int $subtotal): int
    {
        if (!$this->isYearly()) {
            return 0;
        }

        return (int) round($subtotal * PricingSetting::value('yearly_discount_percent', 2) / 100);
    }

    public function preview(?CarbonImmutable $on = null): array
    {
        $on = $on ?? CarbonImmutable::now();

        $subscription = $this->subscriptionLines($on);
        $charges = $this->charges();
        $lines = [...$subscription, ...$this->chargeLines($charges)];

        $subtotal = array_sum(array_column($lines, 'amount_cents'));

        /**
         * The yearly discount only covers the subscription and not one-off
         * charges: someone topping up AI credit should not get two percent off
         * it because they happen to pay per year. So it is counted over the
         * subscription lines themselves, rather than over every line minus a
         * list of excluded kinds -- a new kind of charge would silently be
         * discounted there.
         */
        $discount = $this->yearlyDiscountCents(array_sum(array_column($subscription, 'amount_cents')));

        /**
         * Not clamped to zero. An outstanding credit larger than the lines
         * around it produced an invoice of zero euro while the credit charge
         * was still stamped as processed -- and with that the customer's money
         * was gone. What does not fit, issue() refuses.
         */
        $net = $subtotal - $discount;
        $vat_percent = (int) PricingSetting::value('vat_percent', 21);
        $vat = (int) round($net * $vat_percent / 100);

        return [
            'lines' => $lines,
            'subtotal_cents' => $subtotal,
            'discount_cents' => $discount,
            'total_cents' => $net,
            'vat_percent' => $vat_percent,
            'vat_cents' => $vat,
            'gross_cents' => $net + $vat,
            /** Which charges are on it, so issue() marks those and not one that came in since. */
            'charge_ids' => $charges->pluck('id')->all(),
        ];
    }

    /**
     * Invoices the period around $on, dated $on. $period picks another one: a
     * period that was skipped, or whose invoice was credited. The invoice is
     * still dated $on, the day it is made.
     */
    public function issue(?CarbonImmutable $on = null, ?CarbonImmutable $period = null): Invoice
    {
        $on = $on ?? CarbonImmutable::now();
        $period = $period ?? $on;
        [$start, $end] = $this->periodFor($period);
        $preview = $this->preview($period);

        if (!$this->hasStarted($period)) {
            $starts_on = $this->tenant->subscription_started_on;

            throw new Refusal($starts_on
                ? "{$this->tenant->name} begint pas op " . CarbonImmutable::parse($starts_on)->format('d-m-Y')
                    . '; wat openstaat gaat mee op de eerste factuur.'
                : "{$this->tenant->name} heeft nog geen ingangsdatum en wordt niet gefactureerd.");
        }

        /**
         * No empty invoices. Without this limit every click on "create invoice"
         * produces a new number with nothing on it, and those numbers sit in a
         * continuous series the bookkeeping cannot skip.
         */
        if ($preview['lines'] === []) {
            throw new Refusal('Er valt op dit moment niets te factureren voor ' . $this->tenant->name . '.');
        }

        return DB::connection('central')->transaction(function () use ($preview, $start, $end, $on) {
            $invoice = Invoice::on('central')->create([
                'number' => (new InvoiceNumbers)->next($on),
                'tenant_id' => $this->tenant->id,
                /** Written down, because the customer may be gone by the time this is read back. */
                'tenant_name' => $this->tenant->name,
                'period_start' => $start->toDateString(),
                'period_end' => $end->toDateString(),
                'issued_on' => $on->toDateString(),
                'due_on' => $on->addDays((int) IssuerSetting::value('payment_days', '14'))->toDateString(),
                'subtotal_cents' => $preview['subtotal_cents'],
                'discount_cents' => $preview['discount_cents'],
                'total_cents' => $preview['total_cents'],
                'vat_percent' => $preview['vat_percent'],
                'vat_cents' => $preview['vat_cents'],
                'gross_cents' => $preview['gross_cents'],
            ]);

            foreach ($preview['lines'] as $line) {
                $invoice->lines()->create($line);
            }

            /** Only fixed here, so a failed invoice does not eat them. */
            PendingCharge::on('central')
                ->whereIn('id', $preview['charge_ids'])
                ->whereNull('invoice_id')
                ->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    /**
     * Deletes an invoice that should not have been made, and gives its number
     * back.
     *
     * The series of invoice numbers has no gaps, and no invoice changes once
     * it is in the books. So only the newest invoice in the whole system can
     * go, and only while nobody has it; everything else is corrected with a
     * credit note. What it settled is outstanding again, except a settlement
     * over days before the start, which should never have been on it.
     *
     * Asked again once the counter is locked, on a fresh copy: an invoice
     * mailed or booked a moment ago has left the building after all.
     *
     * @return string The number the next invoice gets.
     */
    public function withdraw(Invoice $invoice): string
    {
        $numbers = new InvoiceNumbers;

        return DB::connection('central')->transaction(function () use ($invoice, $numbers) {
            $numbers->lock($numbers->yearOf($invoice));
            $invoice = Invoice::on('central')->lockForUpdate()->findOrFail($invoice->id);

            if ($refusal = $this->withdrawRefusal($invoice)) {
                throw new Refusal($refusal);
            }

            $period = $invoice->period_start->toDateString();
            $afterwards = $this->settlementsFor($period, after: $invoice);

            $numbers->giveBack($invoice);
            $this->release($invoice);
            $invoice->delete();

            $this->resettle($period, $afterwards);
            $this->forgetSettlementsBeforeTheStart();

            return $numbers->upcoming($numbers->yearOf($invoice));
        });
    }

    public function canWithdraw(Invoice $invoice): bool
    {
        return $this->withdrawRefusal($invoice) === null;
    }

    /** Why an invoice cannot be deleted, or nothing when it can. */
    private function withdrawRefusal(Invoice $invoice): ?string
    {
        if ($invoice->hasLeftTheBuilding()) {
            return "Factuur {$invoice->number} is al de deur uit, of is naar e-Boekhouden gestuurd."
                . ' Terugdraaien gaat met een creditfactuur.';
        }

        if ($invoice->belongsToACredit()) {
            return "Factuur {$invoice->number} hoort bij een creditering en blijft staan.";
        }

        if (!(new InvoiceNumbers)->isNewest($invoice)) {
            return "Factuur {$invoice->number} is niet de laatste factuur: daarna is er nog een gemaakt."
                . ' Een gat in de nummering mag niet; '
                . ($invoice->gross_cents < 0 ? 'wat er anders moet, gaat op een nieuwe factuur.' : 'crediteer hem.');
        }

        return null;
    }

    /**
     * Cancels an invoice that was wrong with a credit note.
     *
     * Every amount with the opposite sign, for the same period, under a number
     * of its own and naming the invoice it cancels. What that invoice settled
     * -- top ups, settlements -- is outstanding again, so the invoice that
     * should have been sent picks it up; the credit note has taken it off.
     *
     * Once only, and never a credit note itself: crediting a credit note is
     * invoicing again, and that is what the button for a new invoice is for.
     */
    public function credit(Invoice $invoice): Invoice
    {
        if ($invoice->credits_invoice_id || $invoice->gross_cents < 0) {
            throw new Refusal("{$invoice->number} is zelf een creditfactuur. Wat er opnieuw gerekend moet worden, gaat op een nieuwe factuur.");
        }

        if ($this->canWithdraw($invoice)) {
            throw new Refusal("{$invoice->number} kan nog weg: het is de laatste factuur en hij is nog niet de deur uit."
                . ' Verwijder hem; dan krijgt de volgende factuur zijn nummer.');
        }

        return DB::connection('central')->transaction(function () use ($invoice) {
            /**
             * The number first: that locks the counter before anything else is
             * read, so what this sees of the series is not from before a
             * delete that was still busy.
             */
            $on = CarbonImmutable::now();
            $number = (new InvoiceNumbers)->next($on);

            $original = Invoice::on('central')->with('lines')->lockForUpdate()->findOrFail($invoice->id);

            if ($original->creditedBy()->exists()) {
                throw new Refusal("{$original->number} is al gecrediteerd.");
            }

            $note = Invoice::on('central')->create([
                'number' => $number,
                'tenant_id' => $original->tenant_id,
                'tenant_name' => $this->tenant->name,
                'credits_invoice_id' => $original->id,
                'period_start' => $original->period_start->toDateString(),
                'period_end' => $original->period_end->toDateString(),
                'issued_on' => $on->toDateString(),
                'due_on' => $on->toDateString(),
                'subtotal_cents' => -$original->subtotal_cents,
                'discount_cents' => -$original->discount_cents,
                'total_cents' => -$original->total_cents,
                'vat_percent' => $original->vat_percent,
                'vat_cents' => -$original->vat_cents,
                'gross_cents' => -$original->gross_cents,
            ]);

            foreach ($original->lines as $line) {
                $note->lines()->create([
                    'description' => $line->description,
                    'kind' => $line->kind,
                    'amount_cents' => -$line->amount_cents,
                ]);
            }

            $period = $original->period_start->toDateString();
            $afterwards = $this->settlementsFor($period, after: $original);

            $this->release($original);
            $this->resettle($period, $afterwards);

            return $note;
        });
    }

    /** What an invoice settled -- topped up credit, settlements -- is outstanding again. */
    private function release(Invoice $invoice): void
    {
        PendingCharge::on('central')->where('invoice_id', $invoice->id)->update(['invoice_id' => null]);
    }

    /**
     * The settlements over a period: the open ones, and with $after also the
     * ones a later invoice already charged that were made after that invoice.
     * A cancellation counts for the period its last day falls in.
     *
     * @return Collection<int, PendingCharge>
     */
    private function settlementsFor(string $period, ?Invoice $after = null): Collection
    {
        $cancelled_in = $this->endsOn() ? $this->periodFor($this->endsOn())[0]->toDateString() : null;

        return PendingCharge::on('central')
            ->where('tenant_id', $this->tenant->id)
            ->where('kind', 'proration')
            ->where(fn ($query) => $query->whereNull('invoice_id')->when($after, fn ($query) => $query
                ->orWhere(fn ($query) => $query
                    ->where('invoice_id', '!=', $after->id)
                    ->where('created_at', '>=', $after->created_at))))
            ->orderBy('id')
            ->get()
            ->filter(fn (PendingCharge $charge) => isset($charge->data['cancellation'])
                ? $cancelled_in === $period
                : ($charge->data['period_start'] ?? null) === $period)
            ->values();
    }

    /**
     * Works settlements out again for a period whose invoice is gone --
     * credited, deleted, or the year started over -- if no other invoice
     * still charges its subscription. An invoice that only carried topped up
     * credit changes nothing about the period.
     *
     * They were worked out against that invoice: "paid at the old price, so
     * the difference over the days to come". Now the next invoice charges the
     * period afresh. A cancellation then needs no settlement -- that invoice
     * stops at the last day -- and a package change is worked out the other
     * way round: over the days already spent on the old package. After
     * several changes in one period that uses the day of the last one, as the
     * line itself already does.
     *
     * Each change once: a settlement worked out again after an earlier credit
     * describes the same change as the one it replaced, and the reversal of
     * that one goes too. All taken back first, then worked out: settle() adds
     * a new settlement to the one already open for the period, and that must
     * not be one of these.
     *
     * @param  Collection<int, PendingCharge>  $charges
     */
    private function resettle(string $period, Collection $charges): void
    {
        if ($this->subscriptionWasInvoicedFor(CarbonImmutable::parse($period))) {
            return;
        }

        $this->pendingCharges()
            ->filter(fn (PendingCharge $reversal) => in_array($reversal->data['withdraws'] ?? null, $charges->modelKeys(), true))
            ->each(fn (PendingCharge $reversal) => $reversal->delete());

        $charges->each(fn (PendingCharge $charge) => $this->reverseSettlement($charge));

        $charges
            ->reject(fn (PendingCharge $charge) => isset($charge->data['cancellation']))
            ->unique(fn (PendingCharge $charge) => implode('|', [
                $charge->data['changed_on'], $charge->data['from_cents'], $charge->data['to_cents'],
            ]))
            ->each(fn (PendingCharge $charge) => $this->prorate(
                $charge->data['from_cents'],
                $charge->data['to_cents'],
                CarbonImmutable::parse($charge->data['changed_on']),
                $charge->data['from_package'],
                $charge->data['to_package'],
            ));
    }

    /**
     * Works the settlements over these periods out again after their invoices
     * were deleted with the year (YearRestart).
     *
     * @param  iterable<int, string>  $periods  the first day of each, yyyy-mm-dd
     */
    public function resettlePeriods(iterable $periods): void
    {
        foreach ($periods as $period) {
            $this->resettle($period, $this->settlementsFor($period));
        }
    }

    /**
     * Takes a settlement back: an open one is deleted, one that an invoice
     * already charged is reversed on the next.
     */
    private function reverseSettlement(PendingCharge $charge): void
    {
        if (!$charge->invoice_id) {
            $charge->delete();

            return;
        }

        PendingCharge::on('central')->create([
            'tenant_id' => $charge->tenant_id,
            'kind' => 'proration',
            'description' => 'Vervalt, stond op ' . Invoice::on('central')->find($charge->invoice_id)?->number . ': '
                . $charge->description,
            'amount_cents' => -$charge->amount_cents,
            'data' => ['withdraws' => $charge->id],
        ]);
    }

    /**
     * Settles a cancellation halfway through a period.
     *
     * Only when that period has been invoiced: then the days after the last day
     * are paid for and not used, and those come off as credit. If it has not
     * been invoiced yet, the next invoice already charges up to and including
     * the last day and there is nothing to settle.
     */
    public function settleCancellation(CarbonImmutable $ends_on): ?PendingCharge
    {
        [$start, $end] = $this->periodFor($ends_on);

        if (!$this->subscriptionWasInvoicedFor($start)) {
            return null;
        }

        $total_days = (int) $start->diffInDays($end->addDay());
        $unused = (int) max(0, $ends_on->addDay()->startOfDay()->diffInDays($end->addDay()));

        if (!$total_days || !$unused) {
            return null;
        }

        $paid = (new TenantSubscription($this->tenant))->monthlyTotalCents() * $this->monthsPerPeriod();
        $amount = -(int) round($paid * $unused / $total_days);

        if ($amount === 0) {
            return null;
        }

        $this->forgetCancellationSettlement();

        return PendingCharge::on('central')->create([
            'tenant_id' => $this->tenant->id,
            'description' => sprintf(
                'Verrekening opzegging per %s (%d van %d dagen niet gebruikt)',
                $ends_on->format('d-m-Y'),
                $unused,
                $total_days,
            ),
            'kind' => 'proration',
            'amount_cents' => $amount,
            'data' => ['cancellation' => true],
        ]);
    }

    /**
     * Removes the credit from a cancellation, for when it is withdrawn. Without
     * this the customer keeps getting money back for days they use after all.
     */
    public function forgetCancellationSettlement(): void
    {
        PendingCharge::on('central')
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('invoice_id')
            ->where('data->cancellation', true)
            ->delete();
    }

    /**
     * Removes the settlements for changes made before the subscription started.
     *
     * Whatever is agreed before the first day is the agreement itself, and
     * prorate() never settles it. But a settlement made while the start date
     * was still earlier stays behind when that date is moved past the change:
     * a line about days on the old price on which the customer had nothing at
     * all, on an invoice for a subscription that has not begun.
     */
    public function forgetSettlementsBeforeTheStart(): void
    {
        if (!$this->tenant->subscription_started_on) {
            return;
        }

        $starts_on = CarbonImmutable::parse($this->tenant->subscription_started_on);

        $this->pendingCharges()
            ->filter(fn (PendingCharge $charge) => isset($charge->data['changed_on'])
                && CarbonImmutable::parse($charge->data['changed_on'])->lessThan($starts_on))
            ->each(fn (PendingCharge $charge) => $charge->delete());
    }

    /**
     * Settles a package change halfway through a period.
     *
     * For this period the customer should pay the old package for the days up
     * to the change and the new one for the days after. Whatever the invoice
     * differs from that is added here as a separate line. That can go two ways,
     * and which way depends only on whether this period has been invoiced:
     *
     * - Already invoiced, at the old price. Then too little was charged for the
     *   days still to come: the difference added over those days.
     * - Not invoiced yet. Then the next invoice charges the new package over
     *   the whole period, including the days the customer was still on the old
     *   package: the difference deducted over those days.
     *
     * Both rules compute towards the same amount. Only the first one was there;
     * on a change halfway through a month that had not been invoiced yet
     * nothing happened and the customer paid the new package from the first of
     * the month instead of from the day of the change.
     */
    public function prorate(
        int $old_monthly_cents,
        int $new_monthly_cents,
        ?CarbonImmutable $on = null,
        ?string $old_package = null,
        ?string $new_package = null,
    ): ?PendingCharge {
        $on = $on ?? CarbonImmutable::now();
        [$start, $end] = $this->periodFor($on);

        $total_days = (int) $start->diffInDays($end->addDay());

        if (!$total_days || $old_monthly_cents === $new_monthly_cents) {
            return null;
        }

        $days_to_come = (int) max(0, $on->startOfDay()->diffInDays($end->addDay()));
        $invoiced = $this->subscriptionWasInvoicedFor($start);
        $days = $invoiced ? $days_to_come : max(0, $total_days - $days_to_come);

        $difference = ($new_monthly_cents - $old_monthly_cents) * $this->monthsPerPeriod();
        $amount = (int) round($difference * $days / $total_days);
        $amount = $invoiced ? $amount : -$amount;

        if ($amount === 0) {
            return null;
        }

        return $this->settle($start, $amount, $days, $total_days, $invoiced, [
            'from_package' => $old_package,
            'to_package' => $new_package,
            'from_cents' => $old_monthly_cents,
            'to_cents' => $new_monthly_cents,
            'changed_on' => $on->toDateString(),
        ]);
    }

    /**
     * Puts the settlement ready, or adds it to this period's.
     *
     * Every change produced its own line at first. Someone switching package
     * halfway through the month and then agreeing a price for that package --
     * two saves -- got two lines with the same description and opposite
     * amounts. Together they were right, but there was no making sense of it.
     * If they cancel out, nothing is left instead of a line of zero euro.
     *
     * Only within the same period. A settlement from last month still waiting
     * for an invoice covers that month's days and a different number of them;
     * adding it to this one would turn two corrections on two different months
     * into an amount nobody can follow.
     *
     * The starting point stays that of the first change, so after three saves
     * the line still says from which package and which amount to which.
     *
     * @param  array{from_package: ?string, to_package: ?string, from_cents: int, to_cents: int, changed_on: string}  $change
     */
    private function settle(
        CarbonImmutable $start,
        int $amount,
        int $days,
        int $total_days,
        bool $invoiced,
        array $change,
    ): ?PendingCharge {
        $standing = PendingCharge::on('central')
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('invoice_id')
            ->where('kind', 'proration')
            ->where('data->period_start', $start->toDateString())
            ->first();

        $before = $standing?->data ?? [];

        $story = [
            ...$change,
            'period_start' => $start->toDateString(),
            'from_package' => $before['from_package'] ?? $change['from_package'],
            'from_cents' => $before['from_cents'] ?? $change['from_cents'],
            'changes' => ($before['changes'] ?? 0) + 1,
        ];

        $description = $this->prorationDescription($story, $days, $total_days, $invoiced);

        if (!$standing) {
            return PendingCharge::on('central')->create([
                'tenant_id' => $this->tenant->id,
                'description' => $description,
                'kind' => 'proration',
                'amount_cents' => $amount,
                'data' => $story,
            ]);
        }

        $together = (int) $standing->amount_cents + $amount;

        if ($together === 0) {
            $standing->delete();

            return null;
        }

        $standing->update([
            'amount_cents' => $together,
            'data' => $story,
            'description' => $description,
        ]);

        return $standing;
    }

    /**
     * What the settlement is about, in a line that can be followed on the
     * invoice: from where, to where, and on which day.
     *
     * For a package change the package names say it. For everything around it
     * -- a module added, a seat more, a price agreement -- they say nothing,
     * and then the monthly amount before and after is the only thing that
     * explains where the settlement comes from. With more than one change the
     * amount is always there, because then the package is not the whole story.
     *
     * The days at the end belong to the package being paid for. If the period
     * was already invoiced at the old price, they are the days still to come on
     * the new package; if it was not, the days already spent on the old one.
     * After several changes that number differs per change and only the day of
     * the last one is left.
     *
     * @param  array{from_package: ?string, to_package: ?string, from_cents: int, to_cents: int, changed_on: string, changes: int}  $story
     */
    private function prorationDescription(array $story, int $days, int $total_days, bool $invoiced): string
    {
        $on = CarbonImmutable::parse($story['changed_on'])->format('d-m-Y');
        $switched = filled($story['from_package']) && filled($story['to_package'])
            && $story['from_package'] !== $story['to_package'];

        $packages = $switched ? sprintf('%s naar %s', $story['from_package'], $story['to_package']) : null;
        $amounts = sprintf(
            '€ %s naar € %s per maand',
            Money::human($story['from_cents']),
            Money::human($story['to_cents']),
        );

        if ($story['changes'] > 1) {
            return sprintf(
                'Verrekening abonnementswijziging: %s (laatste wijziging %s)',
                $packages ? $packages . ', ' . $amounts : $amounts,
                $on,
            );
        }

        $what = $switched
            ? sprintf('pakketwissel %s: %s', $on, $packages)
            : sprintf('abonnementswijziging %s: %s', $on, $amounts);

        $over = $switched
            ? ($invoiced ? $story['to_package'] : $story['from_package'])
            : ($invoiced ? 'de nieuwe prijs' : 'de oude prijs');

        return sprintf('Verrekening %s (%d van %d dagen op %s)', $what, $days, $total_days, $over);
    }
}

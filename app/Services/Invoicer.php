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
     * Without a start date there is nothing to invoice; this fallback only
     * keeps the date arithmetic intact for screens that ask anyway.
     * subscriptionIsDue() refuses such a customer separately.
     */
    private function startedOn(): CarbonImmutable
    {
        return CarbonImmutable::parse(
            $this->tenant->billing_period_started_on
                ?? $this->tenant->subscription_started_on
                ?? now()->startOfMonth()
        );
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

        return [...$this->subscriptionLines($on), ...$this->chargeLines()];
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
    private function subscriptionLines(CarbonImmutable $on): array
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

    /**
     * The one-off charges raised since the previous invoice.
     *
     * @return array<int, array{description: string, kind: string, amount_cents: int}>
     */
    private function chargeLines(): array
    {
        return $this->pendingCharges()->map(fn (PendingCharge $charge) => [
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

        if (!$this->tenant->subscription_started_on) {
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
        return Invoice::on('central')
            ->where('tenant_id', $this->tenant->id)
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
        return $this->preview($on)['lines'] !== [];
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
        $lines = [...$subscription, ...$this->chargeLines()];

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
        ];
    }

    public function issue(?CarbonImmutable $on = null): Invoice
    {
        $on = $on ?? CarbonImmutable::now();
        [$start, $end] = $this->periodFor($on);
        $preview = $this->preview($on);

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
                'number' => $this->nextNumber($on),
                'tenant_id' => $this->tenant->id,
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
                ->where('tenant_id', $this->tenant->id)
                ->whereNull('invoice_id')
                ->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    /**
     * The next invoice number.
     *
     * Continuous per year across all customers rather than per customer: the
     * bookkeeping wants one series. It looks at the highest number of this year
     * and not at the count, so a deleted invoice does not let its number be
     * reused.
     */
    private function nextNumber(CarbonImmutable $on): string
    {
        $prefix = $on->format('Y') . '-LVR-';

        $last = (int) str_replace($prefix, '', (string) Invoice::on('central')
            ->where('number', 'like', $prefix . '%')
            ->orderByRaw('CAST(REPLACE(number, ?, "") AS UNSIGNED) DESC', [$prefix])
            ->value('number'));

        return $prefix . ($last + 1);
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

<?php

namespace App\Services;

use App\Models\Central\Invoice;
use App\Models\Central\IssuerSetting;
use App\Models\Central\PendingCharge;
use App\Models\Central\PricingSetting;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

class Invoicer
{
    public function __construct(private Tenant $tenant) {}

    public function isYearly(): bool
    {
        return $this->tenant->billing_period === 'yearly';
    }

    public function startedOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->tenant->subscription_started_on ?? now()->startOfMonth());
    }

    /**
     * De periode waarin een datum valt, geteld vanaf de startdatum. Zo blijft
     * een klant die op de 12e begon op de 12e factuurdatum houden, ook in
     * februari.
     */
    public function periodFor(CarbonImmutable $on): array
    {
        $start = $this->startedOn();
        $step = $this->isYearly() ? 12 : 1;

        while ($start->addMonths($step)->lessThanOrEqualTo($on)) {
            $start = $start->addMonths($step);
        }

        return [$start, $start->addMonths($step)->subDay()];
    }

    /** @return array<int, array{description: string, kind: string, amount_cents: int}> */
    public function lines(?CarbonImmutable $on = null): array
    {
        $on = $on ?? CarbonImmutable::now();
        [$start, $end] = $this->periodFor($on);

        $subscription = new TenantSubscription($this->tenant);
        $months = $this->isYearly() ? 12 : 1;
        $period = $start->format('d-m-Y') . ' t/m ' . $end->format('d-m-Y');

        /**
         * Het abonnement per post en niet als één bedrag: op de factuur hoort
         * te staan waarvoor betaald wordt. De periode staat alleen achter de
         * eerste regel; hij geldt voor het hele blok en staat ook in de kop.
         */
        $lines = [];

        foreach ($subscription->breakdown() as $index => $line) {
            $lines[] = [
                'description' => $index === 0
                    ? $line['description'] . ' ' . $period . ($months > 1 ? ' (12 maanden)' : '')
                    : $line['description'],
                'kind' => $line['kind'],
                'amount_cents' => $line['amount_cents'] * $months,
            ];
        }

        foreach ($this->pendingCharges() as $charge) {
            $lines[] = [
                'description' => $charge->description,
                'kind' => $charge->kind,
                'amount_cents' => (int) $charge->amount_cents,
            ];
        }

        return $lines;
    }

    /**
     * Een periode die begonnen is en nog geen factuur heeft. Er wordt op
     * period_start gezocht en niet geteld: dan levert een handmatig gemaakte
     * factuur geen tweede op, en een verwijderde factuur wel weer een nieuwe.
     */
    public function isDue(?CarbonImmutable $on = null): bool
    {
        $on = $on ?? CarbonImmutable::now();

        if (!$this->tenant->subscription_started_on) {
            return false;
        }

        [$start] = $this->periodFor($on);

        if ($start->startOfDay()->greaterThan($on->startOfDay())) {
            return false;
        }

        return !Invoice::on('central')
            ->where('tenant_id', $this->tenant->id)
            ->whereDate('period_start', $start->toDateString())
            ->exists();
    }

    public function pendingCharges()
    {
        return PendingCharge::on('central')
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('invoice_id')
            ->orderBy('id')
            ->get();
    }

    public function yearlyDiscountCents(int $subtotal): int
    {
        if (!$this->isYearly()) {
            return 0;
        }

        return (int) round($subtotal * PricingSetting::value('yearly_discount_percent', 2) / 100);
    }

    public function preview(?CarbonImmutable $on = null): array
    {
        $lines = $this->lines($on);
        $subtotal = array_sum(array_column($lines, 'amount_cents'));

        /**
         * De jaarkorting gaat alleen over het abonnement, niet over eenmalige
         * posten: iemand die AI bijkoopt hoort daar geen twee procent op te
         * krijgen omdat hij toevallig per jaar betaalt.
         */
        $subscription_cents = array_sum(array_map(
            fn ($line) => $line['kind'] === 'topup' ? 0 : $line['amount_cents'],
            $lines,
        ));

        $discount = $this->yearlyDiscountCents($subscription_cents);

        $net = max(0, $subtotal - $discount);
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

        return DB::connection('central')->transaction(function () use ($preview, $start, $end, $on) {
            /**
             * Doorlopend per jaar over alle klanten heen, niet per klant: de
             * boekhouding wil één reeks. De teller kijkt naar het hoogste
             * nummer van dit jaar en niet naar het aantal, zodat een verwijderde
             * factuur geen nummer laat hergebruiken.
             */
            $prefix = $on->format('Y') . '-LVR-';

            $last = (int) str_replace($prefix, '', (string) Invoice::on('central')
                ->where('number', 'like', $prefix . '%')
                ->orderByRaw('CAST(REPLACE(number, ?, "") AS UNSIGNED) DESC', [$prefix])
                ->value('number'));

            $number = $prefix . ($last + 1);

            $invoice = Invoice::on('central')->create([
                'number' => $number,
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

            /** Pas hier vastgezet, zodat een mislukte factuur ze niet opsnoept. */
            PendingCharge::on('central')
                ->where('tenant_id', $this->tenant->id)
                ->whereNull('invoice_id')
                ->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    /**
     * Verrekent een pakketwissel halverwege een periode: wat er nog aan dagen
     * over is, tegen het verschil in maandprijs.
     */
    public function prorate(int $old_monthly_cents, int $new_monthly_cents, ?CarbonImmutable $on = null): ?PendingCharge
    {
        $on = $on ?? CarbonImmutable::now();
        [$start, $end] = $this->periodFor($on);

        $total_days = $start->diffInDays($end->addDay());
        $left = max(0, $on->startOfDay()->diffInDays($end->addDay()));

        if (!$total_days || !$left || $old_monthly_cents === $new_monthly_cents) {
            return null;
        }

        $months = $this->isYearly() ? 12 : 1;
        $difference = ($new_monthly_cents - $old_monthly_cents) * $months;
        $amount = (int) round($difference * $left / $total_days);

        if ($amount === 0) {
            return null;
        }

        return PendingCharge::on('central')->create([
            'tenant_id' => $this->tenant->id,
            'description' => sprintf('Verrekening pakketwissel %s (%d van %d dagen)', $on->format('d-m-Y'), $left, $total_days),
            'kind' => 'proration',
            'amount_cents' => $amount,
        ]);
    }
}

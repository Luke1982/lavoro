<?php

namespace App\Services;

use App\Exceptions\Refusal;
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
     *
     * Elke periode wordt vanaf de oorspronkelijke startdatum uitgerekend en
     * niet stap voor stap opgeteld, en zonder over te lopen naar de volgende
     * maand. Wie op de 31e begon schoof anders voorgoed op: 31 januari plus een
     * maand is 3 maart, en vanaf dan lag de factuurdatum op de 3e. Nu wordt hij
     * in korte maanden alleen ingekort -- 31 januari, 28 februari, 31 maart --
     * en blijft de klant op zijn eigen dag.
     */
    public function periodFor(CarbonImmutable $on): array
    {
        $start = $this->startedOn();
        $step = $this->isYearly() ? 12 : 1;
        $periods = 0;

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
        [$start, $end] = $this->periodFor($on);

        $months = $this->isYearly() ? 12 : 1;
        $period = $start->format('d-m-Y') . ' t/m ' . $end->format('d-m-Y');

        $lines = [];

        /**
         * Het abonnement alleen als deze periode nog niet in rekening is
         * gebracht. Zonder die voorwaarde zet een tussentijdse factuur voor
         * bijgekocht tegoed de hele maand er nog een keer bij.
         *
         * Per post en niet als één bedrag: op de factuur hoort te staan
         * waarvoor betaald wordt. De periode staat alleen achter de eerste
         * regel; hij geldt voor het hele blok en staat ook in de kop.
         */
        if ($this->subscriptionIsDue($on)) {
            foreach ((new TenantSubscription($this->tenant))->breakdown() as $index => $line) {
                $lines[] = [
                    'description' => $index === 0
                        ? $line['description'] . ' ' . $period . ($months > 1 ? ' (12 maanden)' : '')
                        : $line['description'],
                    'kind' => $line['kind'],
                    'amount_cents' => $line['amount_cents'] * $months,
                ];
            }
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
     * Is het abonnement voor de lopende periode al in rekening gebracht?
     *
     * Er wordt gezocht op een factuur voor deze periode die het abonnement
     * ook echt bevat, en niet alleen op het bestaan van een factuur. Een
     * tussentijdse factuur voor bijgekocht tegoed valt in dezelfde periode;
     * die mag de maandfactuur niet wegdrukken.
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

        return !$this->subscriptionWasInvoicedFor($start);
    }

    /**
     * Staat het abonnement van deze periode al op een factuur? Er wordt op de
     * abonnementsregel gezocht en niet op het bestaan van een factuur: een
     * tussentijdse factuur voor bijgekocht tegoed valt in dezelfde periode.
     *
     * Gezocht wordt op een factuur waar de eerste dag van deze periode binnen
     * valt, en niet op een factuur die precies op die dag begint. Verschuift de
     * indeling ooit -- een gecorrigeerde startdatum, of de reparatie van de
     * maandsprong -- dan zou een zoektocht op de exacte dag niets vinden en
     * werden dagen die al betaald zijn een tweede keer in rekening gebracht.
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
     * Valt er iets te factureren? Het abonnement van een nieuwe periode, of
     * losse posten die sinds de vorige factuur zijn ontstaan -- een
     * pakketwissel, bijgekocht AI-tegoed. Is er niets van beide, dan levert
     * factureren een lege factuur op en dat hoort niet te kunnen.
     */
    public function isDue(?CarbonImmutable $on = null): bool
    {
        if (!$this->subscriptionIsDue($on) && $this->pendingCharges()->isEmpty()) {
            return false;
        }

        /**
         * Staat er meer tegoed open dan er te factureren valt -- na een
         * pakketverlaging bijvoorbeeld -- dan valt er nu niets te sturen. Het
         * tegoed blijft staan en gaat van de volgende factuur af.
         */
        return $this->preview($on)['total_cents'] >= 0;
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
            fn ($line) => in_array($line['kind'], ['topup', 'proration'], true) ? 0 : $line['amount_cents'],
            $lines,
        ));

        $discount = $this->yearlyDiscountCents($subscription_cents);

        /**
         * Niet afgekapt op nul. Een openstaand tegoed dat groter is dan de
         * regels eromheen leverde anders een factuur op van nul euro, terwijl
         * de tegoedpost wel als verwerkt werd afgestempeld -- en daarmee was
         * het geld van de klant weg. Wat er niet uit kan, weigert issue().
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
         * Geen lege facturen. Zonder deze grens levert elke klik op "factuur
         * aanmaken" een nieuw nummer op met niets erop, en die nummers zitten
         * in een doorlopende reeks die de boekhouding niet kan overslaan.
         */
        if ($preview['lines'] === []) {
            throw new Refusal('Er valt op dit moment niets te factureren voor ' . $this->tenant->name . '.');
        }

        /**
         * Een factuur is nooit negatief -- de bedragen staan als positief getal
         * in de database en een incasso van een negatief bedrag bestaat niet.
         * Het tegoed blijft dus staan tot er genoeg tegenover staat.
         */
        if ($preview['total_cents'] < 0) {
            throw new Refusal('Er staat meer tegoed open voor ' . $this->tenant->name
                . ' dan er nu te factureren valt. Dat tegoed blijft staan en gaat van de'
                . ' volgende factuur af.');
        }

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
     *
     * Alleen over dagen die al tegen de oude prijs betaald zijn. Is deze
     * periode nog niet gefactureerd, dan zet de eerstvolgende factuur het
     * nieuwe pakket al over de hele periode in rekening; een verrekening
     * erbij bracht het verschil een tweede keer in rekening -- bij een wissel
     * op de eerste dag van de periode zelfs het volle verschil.
     */
    public function prorate(int $old_monthly_cents, int $new_monthly_cents, ?CarbonImmutable $on = null): ?PendingCharge
    {
        $on = $on ?? CarbonImmutable::now();
        [$start, $end] = $this->periodFor($on);

        if (!$this->subscriptionWasInvoicedFor($start)) {
            return null;
        }

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

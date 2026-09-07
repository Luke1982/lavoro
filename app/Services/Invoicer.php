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
     * Zonder ingangsdatum valt er niets te factureren; deze terugval houdt
     * alleen de datumrekensom heel voor schermen die er toch naar vragen.
     * subscriptionIsDue() weigert zo'n klant apart.
     */
    private function startedOn(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->tenant->subscription_started_on ?? now()->startOfMonth());
    }

    /** Een maand of een jaar, in maanden. Overal hetzelfde getal. */
    private function monthsPerPeriod(): int
    {
        return $this->isYearly() ? 12 : 1;
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
        $step = $this->monthsPerPeriod();
        $periods = 0;

        /**
         * Tellend en niet uitgerekend uit het aantal maanden ertussen: die twee
         * zijn het oneens rond het eind van de maand. Van 31 januari naar
         * 28 februari is nul hele maanden, terwijl 28 februari wel degelijk de
         * volgende periode begint.
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
     * Het abonnement van deze periode, uitgesplitst, en leeg zodra die periode
     * al in rekening is gebracht: zonder die voorwaarde zet een tussentijdse
     * factuur voor bijgekocht tegoed de hele maand er nog een keer bij.
     *
     * Per post en niet als één bedrag: op de factuur hoort te staan waarvoor
     * betaald wordt. De periode staat alleen achter de eerste regel; hij geldt
     * voor het hele blok en staat ook in de kop.
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
        $period = $start->format('d-m-Y') . ' t/m ' . $end->format('d-m-Y');
        $lines = [];

        foreach ((new TenantSubscription($this->tenant))->breakdown() as $index => $line) {
            $description = $index === 0
                ? $line['description'] . ' ' . $period . ($months > 1 ? ' (12 maanden)' : '')
                : $line['description'];

            /**
             * Staat er een afgesproken prijs op deze regel, dan hoort de gewone
             * prijs erbij: over een jaar of twee weet niemand meer waarom er
             * een ander bedrag stond, en de klant hoort te zien dat het een
             * afspraak was en geen fout.
             */
            if (isset($line['regular_cents'])) {
                $description .= ', normaal € ' . Money::human($line['regular_cents'] * $months)
                    . ', speciale prijsafspraak';
            }

            $lines[] = [
                'description' => $description,
                'kind' => $line['kind'],
                'amount_cents' => $line['amount_cents'] * $months,
            ];
        }

        return $lines;
    }

    /**
     * De losse posten die sinds de vorige factuur zijn ontstaan.
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

    /** @return Collection<int, PendingCharge> */
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
         * De jaarkorting gaat alleen over het abonnement en niet over losse
         * posten: wie AI bijkoopt hoort daar geen twee procent op te krijgen
         * omdat hij toevallig per jaar betaalt. Daarom wordt er geteld over de
         * abonnementsregels zelf, en niet over alle regels op een lijstje
         * uitgezonderde soorten na -- een nieuwe soort post zou daar
         * stilzwijgend korting op krijgen.
         */
        $discount = $this->yearlyDiscountCents(array_sum(array_column($subscription, 'amount_cents')));

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

            /** Pas hier vastgezet, zodat een mislukte factuur ze niet opsnoept. */
            PendingCharge::on('central')
                ->where('tenant_id', $this->tenant->id)
                ->whereNull('invoice_id')
                ->update(['invoice_id' => $invoice->id]);

            return $invoice;
        });
    }

    /**
     * Het volgende factuurnummer.
     *
     * Doorlopend per jaar over alle klanten heen en niet per klant: de
     * boekhouding wil één reeks. Er wordt naar het hoogste nummer van dit jaar
     * gekeken en niet naar het aantal, zodat een verwijderde factuur zijn
     * nummer niet laat hergebruiken.
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
     * Verrekent een pakketwissel halverwege een periode.
     *
     * De klant hoort over deze periode het oude pakket te betalen voor de
     * dagen tot de wissel en het nieuwe voor de dagen erna. Wat de factuur
     * daarvan afwijkt, staat hier als losse regel bij. Dat kan twee kanten op,
     * en welke kant hangt er alleen van af of deze periode al gefactureerd is:
     *
     * - Al gefactureerd, tegen de oude prijs. Dan is er te weinig gerekend
     *   voor de dagen die nog komen: het verschil erbij over die dagen.
     * - Nog niet gefactureerd. Dan zet de eerstvolgende factuur het nieuwe
     *   pakket over de hele periode in rekening, ook over de dagen die de
     *   klant nog op het oude pakket zat: het verschil eraf over die dagen.
     *
     * Allebei die regels rekenen naar hetzelfde bedrag toe. Alleen de eerste
     * stond er; bij een wissel halverwege een nog niet gefactureerde maand
     * gebeurde er niets en betaalde de klant het nieuwe pakket vanaf de eerste
     * van de maand in plaats van vanaf de dag van de wissel.
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
        $days_gone = max(0, $total_days - $days_to_come);

        $difference = ($new_monthly_cents - $old_monthly_cents) * $this->monthsPerPeriod();
        $invoiced = $this->subscriptionWasInvoicedFor($start);

        $days = $invoiced ? $days_to_come : $days_gone;
        $amount = (int) round($difference * $days / $total_days);
        $amount = $invoiced ? $amount : -$amount;

        if ($amount === 0) {
            return null;
        }

        /**
         * Bij elkaar in een regel, want ze komen toch op dezelfde factuur.
         *
         * Elke wijziging leverde eerst zijn eigen verrekening op. Wie een
         * module aanzette en daarna de prijs ervan afsprak, kreeg twee regels
         * met dezelfde omschrijving en tegengestelde bedragen -- samen klopte
         * het, maar er viel niets van te maken. Heffen ze elkaar op, dan blijft
         * er niets staan in plaats van een regel van nul euro.
         *
         * Waar het vandaan komt gaat mee in de rij: het vertrekpunt blijft dat
         * van de eerste wijziging, zodat de regel ook na drie wijzigingen nog
         * zegt van welk pakket en welk bedrag naar welk.
         */
        $standing = PendingCharge::on('central')
            ->where('tenant_id', $this->tenant->id)
            ->whereNull('invoice_id')
            ->where('kind', 'proration')
            ->first();

        $came_from = $standing?->data ?? [];

        $story = [
            'from_package' => $came_from['from_package'] ?? $old_package,
            'to_package' => $new_package,
            'from_cents' => $came_from['from_cents'] ?? $old_monthly_cents,
            'to_cents' => $new_monthly_cents,
            'changed_on' => $on->toDateString(),
            'changes' => ($came_from['changes'] ?? 0) + 1,
            'days' => $days,
            'total_days' => $total_days,
            'invoiced' => $invoiced,
        ];

        if ($standing) {
            $together = (int) $standing->amount_cents + $amount;

            if ($together === 0) {
                $standing->delete();

                return null;
            }

            $standing->update([
                'amount_cents' => $together,
                'data' => $story,
                'description' => $this->prorationDescription($story),
            ]);

            return $standing;
        }

        return PendingCharge::on('central')->create([
            'tenant_id' => $this->tenant->id,
            'description' => $this->prorationDescription($story),
            'kind' => 'proration',
            'amount_cents' => $amount,
            'data' => $story,
        ]);
    }

    /**
     * Waar de verrekening over gaat, in een regel die op de factuur te volgen
     * is: waarvandaan, waarnaartoe, en op welke dag.
     *
     * Bij een pakketwissel zeggen de pakketnamen het. Bij alles daaromheen --
     * een module erbij, een plek meer, een prijsafspraak -- zeggen ze niets,
     * en dan is het maandbedrag voor en na het enige dat uitlegt waar de
     * verrekening vandaan komt. Bij meer dan een wijziging staat het bedrag er
     * altijd bij, want dan is het pakket niet het hele verhaal.
     *
     * De dagen achteraan horen bij het pakket dat ervoor betaald wordt. Was de
     * periode al gefactureerd tegen de oude prijs, dan gaat het om de dagen die
     * nog op het nieuwe pakket komen; was hij dat niet, dan om de dagen die al
     * op het oude pakket zaten. Na meerdere wijzigingen verschilt dat aantal
     * per wijziging en staat er alleen nog de dag van de laatste.
     *
     * @param  array<string, mixed>  $story
     */
    private function prorationDescription(array $story): string
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
            ? ($story['invoiced'] ? $story['to_package'] : $story['from_package'])
            : ($story['invoiced'] ? 'de nieuwe prijs' : 'de oude prijs');

        return sprintf(
            'Verrekening %s (%d van %d dagen op %s)',
            $what,
            $story['days'],
            $story['total_days'],
            $over,
        );
    }
}

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
        return CarbonImmutable::parse(
            $this->tenant->billing_period_started_on
                ?? $this->tenant->subscription_started_on
                ?? now()->startOfMonth()
        );
    }

    /**
     * Vanaf welke dag een nieuwe betaaltermijn moet gaan lopen.
     *
     * De eerste periode die nog niet gefactureerd is. Is de lopende al betaald,
     * dan begint de nieuwe termijn daarna: anders zou een klant die in maart
     * van maand naar jaar gaat, een jaar in rekening krijgen dat begint in een
     * maand waarvoor hij al betaald heeft.
     */
    public function termStartsOn(?CarbonImmutable $on = null): CarbonImmutable
    {
        [$start, $end] = $this->periodFor($on ?? CarbonImmutable::now());

        return $this->subscriptionWasInvoicedFor($start) ? $end->addDay() : $start;
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
        $lines = [];

        foreach ((new TenantSubscription($this->tenant))->breakdown() as $index => $line) {
            /**
             * Wat niet de hele periode meeliep, wordt naar rato gerekend: wie
             * op de zevende een module erbij neemt betaalt de dagen die er nog
             * van de maand over zijn, en wie halverwege opzegt betaalt tot en
             * met de dag dat het stopt.
             */
            $window = $this->activeWindow($line, $start, $end);
            $from = ($window['from'] ?? $start)->format('d-m-Y');
            $to = ($window['to'] ?? $end)->format('d-m-Y');
            $part = $window ? sprintf(' (%d van %d dagen)', $window['days'], $window['total']) : '';

            $description = $index === 0
                ? $line['description'] . ' ' . $from . ' t/m ' . $to . ($months > 1 ? ' (12 maanden)' : '') . $part
                : $line['description'] . ($window ? ' ' . $from . ' t/m ' . $to . $part : '');

            if ($window) {
                $line['amount_cents'] = (int) round($line['amount_cents'] * $window['days'] / $window['total']);
            }

            /**
             * Staat er een afgesproken prijs op deze regel, dan hoort de gewone
             * prijs erbij: over een jaar of twee weet niemand meer waarom er
             * een ander bedrag stond, en de klant hoort te zien dat het een
             * afspraak was en geen fout.
             *
             * Dat is de prijs uit de catalogus, ook als er maar een deel van de
             * periode gerekend wordt. Naar rato meerekenen leverde een bedrag
             * op dat nergens bestaat -- 'normaal € 18,00' voor een module die
             * gewoon € 22,50 kost -- en hoeveel dagen het betreft staat al
             * voor op de regel.
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
     * De dag waarop de regel is aangezet, of niets als dat niet bijgehouden
     * wordt. Bij een bundel telt de laatste van de modules erin: pas toen was
     * de bundel compleet en werd hij als bundel in rekening gebracht.
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

    /** De laatste dag van het abonnement, als er is opgezegd. */
    private function endsOn(): ?CarbonImmutable
    {
        return $this->tenant->subscription_ends_on
            ? CarbonImmutable::parse($this->tenant->subscription_ends_on)
            : null;
    }

    /**
     * Het stuk van deze periode waarvoor de regel meetelt, als dat niet de
     * hele periode is: vanaf de dag dat hij aanging tot en met de dag dat het
     * abonnement stopt. Niets zodra hij de hele periode meetelt.
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

        /** Opgezegd voordat deze periode begon: er valt niets meer te sturen. */
        if ($this->endsOn()?->lessThan($start)) {
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
    /**
     * Periodes die voorbij zijn en waarvoor nooit een abonnement in rekening is
     * gebracht.
     *
     * Er wordt altijd maar een periode gefactureerd: die van vandaag. Wordt er
     * een maand overgeslagen -- de cron staat stil, de knop wordt niet gedrukt
     * -- dan komt die maand nooit meer terug. De klant werkt door en er gaat
     * stilzwijgend een maand omzet verloren. Dit maakt zichtbaar welke.
     *
     * Er wordt niet vanzelf alsnog gefactureerd: een klant met een startdatum
     * ver in het verleden zou daarmee in een klap een stapel facturen krijgen,
     * en de openstaande posten van vandaag zouden op een oude factuur belanden.
     * Dat hoort iemand met de hand recht te zetten.
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
         * Geteld vanaf de ingangsdatum en niet vanaf het anker van de huidige
         * termijn: gaat iemand van maand naar jaar, dan verschuift dat anker
         * naar vandaag en zouden de maanden daarvoor uit beeld raken -- juist
         * de maanden waar het hier om gaat.
         */
        $start = CarbonImmutable::parse($this->tenant->subscription_started_on);
        $step = $this->monthsPerPeriod();
        $missed = [];

        /** Een grens, zodat een startdatum uit 2015 hier geen honderd vragen stelt. */
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
     * Verrekent een opzegging halverwege een periode.
     *
     * Alleen als die periode al gefactureerd is: dan zijn de dagen na de
     * laatste dag wel betaald en niet gebruikt, en die gaan er als tegoed af.
     * Is er nog niet gefactureerd, dan rekent de eerstvolgende factuur al tot
     * en met de laatste dag en valt er niets te verrekenen.
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
     * Haalt het tegoed van een opzegging weg, voor als die wordt ingetrokken.
     * Zonder dit blijft de klant het geld terugkrijgen voor dagen die hij toch
     * gewoon gebruikt.
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
     * Zet de verrekening klaar, of telt hem op bij die van deze periode.
     *
     * Elke wijziging leverde eerst zijn eigen regel op. Wie halverwege de maand
     * van pakket wisselde en daarna een prijs voor dat pakket afsprak -- twee
     * keer opslaan -- kreeg twee regels met dezelfde omschrijving en
     * tegengestelde bedragen. Samen klopte het, maar er viel niets van te
     * maken. Heffen ze elkaar op, dan blijft er niets staan in plaats van een
     * regel van nul euro.
     *
     * Alleen binnen dezelfde periode. Een verrekening van vorige maand die nog
     * op een factuur wacht, gaat over de dagen van die maand en over een ander
     * aantal dagen; die bij deze optellen zou twee correcties op twee
     * verschillende maanden tot een onnavolgbaar bedrag maken.
     *
     * Het vertrekpunt blijft dat van de eerste wijziging, zodat de regel ook na
     * drie keer opslaan nog zegt van welk pakket en van welk bedrag naar welk.
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

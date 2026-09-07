<?php

namespace App\Services;

use App\Models\Central\Module;
use App\Models\Central\ModuleBundle;
use App\Models\Central\Package;
use App\Models\Central\PricingSetting;
use App\Models\Central\Reseller;
use App\Models\Tenant;
use Carbon\Carbon;

class TenantSubscription
{
    private ?Package $package = null;

    private bool $package_looked_up = false;

    /** @var array<int, array{description: string, kind: string, amount_cents: int, regular_cents?: int}>|null */
    private ?array $chargeable = null;

    public function __construct(private Tenant $tenant) {}

    /**
     * Het pakket van deze klant, een keer opgezocht. Stond op twee plekken los
     * opgehaald, dus elke prijsberekening deed dezelfde vraag twee keer.
     */
    private function package(): ?Package
    {
        if (!$this->package_looked_up) {
            $this->package = Package::on('central')->where('key', $this->tenant->package_key)->first();
            $this->package_looked_up = true;
        }

        return $this->package;
    }

    /** De naam zoals hij op de factuur hoort te staan, of niets bij een onbekend pakket. */
    public function packageName(): ?string
    {
        return $this->package()?->name;
    }

    /**
     * Wat het pakket zelf kost: de afgesproken prijs, of anders die uit de
     * catalogus. Zonder de plekken, modules en opslag die er los bijkomen.
     */
    public function packageCents(): int
    {
        return $this->tenant->price_override_cents !== null
            ? (int) $this->tenant->price_override_cents
            : (int) ($this->package()?->price_cents ?? 0);
    }

    public function monthlyTotalCents(): int
    {
        return max(0, $this->beforeDiscountCents() - $this->discountCents() - $this->couponDiscountCents());
    }

    /**
     * Het abonnement uitgesplitst, zodat op de factuur te zien is waarvoor
     * betaald wordt in plaats van één bedrag. De regels tellen op tot
     * monthlyTotalCents(); kortingen staan er als negatieve regel tussen.
     *
     * @return array<int, array{description: string, kind: string, amount_cents: int}>
     */
    /**
     * Het abonnement uitgesplitst, zodat op de factuur te zien is waarvoor
     * betaald wordt in plaats van één bedrag. De regels tellen op tot
     * monthlyTotalCents(); kortingen staan er als negatieve regel tussen.
     *
     * @return array<int, array{description: string, kind: string, amount_cents: int, regular_cents?: int}>
     */
    public function breakdown(): array
    {
        return array_merge($this->chargeableLines(), $this->discountLines());
    }

    /**
     * Alles waarvoor betaald wordt, zonder de kortingen: het pakket, de extra
     * plekken, de modules en de opslag.
     *
     * Dit is de enige plek waar die opbouw staat. De korting rekent over de
     * som hiervan, dus als de factuurregels en die som elk hun eigen sommetje
     * maakten, konden ze uit elkaar gaan lopen zonder dat iets dat merkt.
     *
     * @return array<int, array{description: string, kind: string, amount_cents: int, regular_cents?: int}>
     */
    private function chargeableLines(): array
    {
        if ($this->chargeable !== null) {
            return $this->chargeable;
        }

        $package = $this->package();
        $agreed = $this->tenant->price_override_cents;

        /**
         * Een afgesproken prijs geldt voor het pakket, niet voor de rest. Wat
         * er los bijgekocht wordt -- plekken, modules, opslag -- komt er
         * gewoon bovenop; anders zou de klant die erbij neemt daar niets voor
         * betalen. Voor zo'n module valt een eigen prijs af te spreken.
         */
        $lines = [array_filter([
            'description' => 'Abonnement Lavoro' . ($package?->name ? ' ' . $package->name : ''),
            'kind' => 'subscription',
            'amount_cents' => $agreed !== null ? (int) $agreed : (int) ($package->price_cents ?? 0),
            'regular_cents' => $agreed !== null ? (int) ($package->price_cents ?? 0) : null,
        ], fn ($value) => $value !== null)];

        foreach ([
            ['extra_field_seats', 'extra_field_cents', 'Extra buitendienstplek'],
            ['extra_office_seats', 'extra_office_cents', 'Extra kantoorplek'],
        ] as [$count_field, $price_field, $label]) {
            $count = (int) $this->tenant->{$count_field};

            if (!$count) {
                continue;
            }

            $lines[] = [
                'description' => $label . ' (' . $count . ' x)',
                'kind' => 'seats',
                'amount_cents' => $count * (int) ($package->{$price_field} ?? 0),
            ];
        }

        foreach ($this->moduleLines() as $line) {
            $lines[] = $line;
        }

        if ($storage = $this->storageCents()) {
            $lines[] = [
                'description' => 'Extra opslag (' . $this->extraStorageGb() . ' GB)',
                'kind' => 'storage',
                'amount_cents' => $storage,
            ];
        }

        return $this->chargeable = $lines;
    }

    /** @return array<int, array{description: string, kind: string, amount_cents: int}> */
    private function discountLines(): array
    {
        $lines = [];

        if ($discount = $this->discountCents()) {
            $lines[] = [
                'description' => $this->tenant->discount_percent
                    ? 'Korting ' . (int) $this->tenant->discount_percent . '%'
                    : 'Korting',
                'kind' => 'discount',
                'amount_cents' => -$discount,
            ];
        }

        if ($coupon = $this->couponDiscountCents()) {
            $lines[] = [
                'description' => 'Kortingsbon ' . (int) $this->tenant->coupon_discount_percent . '%',
                'kind' => 'discount',
                'amount_cents' => -$coupon,
            ];
        }

        return $lines;
    }

    /**
     * De modules van deze klant, met per module de prijs die voor hem geldt.
     *
     * Een bundel vervangt de losse prijzen van de modules die erin zitten,
     * maar alleen als de klant ze allemaal heeft: anders betaalt iemand voor
     * een korting die hij niet krijgt. Is er voor een van die modules een
     * eigen prijs afgesproken, dan gaat die voor -- een afspraak die iemand
     * met de hand gemaakt heeft, hoort niet overreden te worden door een
     * bundelprijs.
     *
     * @return array<int, array{description: string, kind: string, amount_cents: int, regular_cents?: int}>
     */
    private function moduleLines(): array
    {
        $keys = collect($this->tenant->modules ?? []);
        /** Zonder bedrag is het geen afspraak; anders zou een lege rij wel de bundel wegdrukken. */
        $agreed = collect($this->tenant->module_prices ?? [])->filter(fn ($price) => $price !== null);
        $lines = [];

        foreach (ModuleBundle::on('central')->get() as $bundle) {
            $complete = collect($bundle->module_keys)->every(fn ($key) => $keys->contains($key));
            $bargained = collect($bundle->module_keys)->contains(fn ($key) => $agreed->has($key));

            if (!$complete || $bargained) {
                continue;
            }

            $lines[] = [
                'description' => $bundle->name,
                'kind' => 'module',
                'amount_cents' => (int) $bundle->price_cents,
                'module_keys' => $bundle->module_keys,
            ];

            $keys = $keys->reject(fn ($key) => in_array($key, $bundle->module_keys, true));
        }

        foreach (Module::on('central')->whereIn('key', $keys)->orderBy('sort_order')->get() as $module) {
            $own = $agreed->get($module->key);

            $lines[] = array_filter([
                'description' => $module->name,
                'kind' => 'module',
                'amount_cents' => $own !== null ? (int) $own : (int) $module->price_cents,
                'regular_cents' => $own !== null ? (int) $module->price_cents : null,
                'module_keys' => [$module->key],
            ], fn ($value) => $value !== null);
        }

        return $lines;
    }

    /**
     * De kortingsbon loopt af, de handmatige korting niet. Ze staan naast
     * elkaar: een klant die met een bon binnenkwam kan daarnaast nog iets
     * toegezegd hebben gekregen.
     */
    public function couponDiscountCents(): int
    {
        $until = $this->tenant->coupon_discount_until;
        $percent = (int) ($this->tenant->coupon_discount_percent ?? 0);

        if (!$percent || !$until || now()->startOfDay()->gt(Carbon::parse($until))) {
            return 0;
        }

        return (int) round($this->beforeDiscountCents() * $percent / 100);
    }

    /** Wat de reseller deze maand verdient aan deze klant. */
    public function commissionCents(): int
    {
        if (!$this->tenant->reseller_id) {
            return 0;
        }

        $percent = (int) (Reseller::on('central')
            ->find($this->tenant->reseller_id)?->commission_percent ?? 0);

        return (int) round($this->monthlyTotalCents() * $percent / 100);
    }

    /** Wat het zou kosten zonder korting -- het bedrag waar de korting op rekent. */
    public function beforeDiscountCents(): int
    {
        return array_sum(array_column($this->chargeableLines(), 'amount_cents'));
    }

    /**
     * Procent eerst, dan het vaste bedrag. Andersom zou een korting van tien
     * euro plus tien procent minder opleveren dan de klant is toegezegd.
     */
    public function discountCents(): int
    {
        $before = $this->beforeDiscountCents();

        /** Een korting is een bedrag of een percentage, nooit allebei. */
        if ($this->tenant->discount_percent) {
            return min($before, (int) round($before * (int) $this->tenant->discount_percent / 100));
        }

        return min($before, (int) ($this->tenant->discount_cents ?? 0));
    }

    private function extraStorageGb(): int
    {
        return max(0, (int) $this->tenant->storage_limit_gb - PricingSetting::value('included_storage_gb', 50));
    }

    private function storageCents(): int
    {
        return $this->extraStorageGb() * PricingSetting::value('storage_extra_per_gb_cents', 50);
    }
}

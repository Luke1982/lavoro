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
    public function __construct(private Tenant $tenant) {}

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
    public function breakdown(): array
    {
        $package = Package::on('central')->where('key', $this->tenant->package_key)->first();
        $package_name = 'Abonnement Lavoro' . ($package?->name ? ' ' . $package->name : '');

        /** Een afgesproken prijs vervangt de opbouw; die valt niet uit te splitsen. */
        if ($this->tenant->price_override_cents !== null) {
            $lines = [[
                'description' => $package_name,
                'kind' => 'subscription',
                'amount_cents' => (int) $this->tenant->price_override_cents,
            ]];

            return array_merge($lines, $this->discountLines());
        }

        $lines = [[
            'description' => $package_name,
            'kind' => 'subscription',
            'amount_cents' => (int) ($package->price_cents ?? 0),
        ]];

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
            $included = PricingSetting::value('included_storage_gb', 50);
            $extra = max(0, (int) $this->tenant->storage_limit_gb - $included);

            $lines[] = [
                'description' => 'Extra opslag (' . $extra . ' GB)',
                'kind' => 'storage',
                'amount_cents' => $storage,
            ];
        }

        return array_merge($lines, $this->discountLines());
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

    /** @return array<int, array{description: string, kind: string, amount_cents: int}> */
    private function moduleLines(): array
    {
        $keys = collect($this->tenant->modules ?? []);
        $lines = [];

        foreach (ModuleBundle::on('central')->get() as $bundle) {
            if (collect($bundle->module_keys)->every(fn ($k) => $keys->contains($k))) {
                $lines[] = [
                    'description' => $bundle->name,
                    'kind' => 'module',
                    'amount_cents' => (int) $bundle->price_cents,
                ];
                $keys = $keys->reject(fn ($k) => in_array($k, $bundle->module_keys, true));
            }
        }

        foreach (Module::on('central')->whereIn('key', $keys)->orderBy('sort_order')->get() as $module) {
            $lines[] = [
                'description' => $module->name,
                'kind' => 'module',
                'amount_cents' => (int) $module->price_cents,
            ];
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
        if ($this->tenant->price_override_cents !== null) {
            return (int) $this->tenant->price_override_cents;
        }

        $package = Package::on('central')->where('key', $this->tenant->package_key)->first();

        $total = (int) ($package->price_cents ?? 0)
            + (int) $this->tenant->extra_field_seats * (int) ($package->extra_field_cents ?? 0)
            + (int) $this->tenant->extra_office_seats * (int) ($package->extra_office_cents ?? 0);

        return $total + $this->moduleCents() + $this->storageCents();
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

    /**
     * Een bundel vervangt de losse prijzen van de modules die erin zitten, maar
     * alleen als de tenant ze allemaal heeft. Anders betaalt iemand voor een
     * korting die hij niet krijgt.
     */
    private function moduleCents(): int
    {
        $keys = collect($this->tenant->modules ?? []);
        $total = 0;

        foreach (ModuleBundle::on('central')->get() as $bundle) {
            if (collect($bundle->module_keys)->every(fn ($k) => $keys->contains($k))) {
                $total += (int) $bundle->price_cents;
                $keys = $keys->reject(fn ($k) => in_array($k, $bundle->module_keys, true));
            }
        }

        return $total + (int) Module::on('central')->whereIn('key', $keys)->sum('price_cents');
    }

    private function storageCents(): int
    {
        $included = PricingSetting::value('included_storage_gb', 50);
        $extra = max(0, (int) $this->tenant->storage_limit_gb - $included);

        return $extra * PricingSetting::value('storage_extra_per_gb_cents', 50);
    }
}

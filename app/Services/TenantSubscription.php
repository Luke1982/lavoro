<?php

namespace App\Services;

use App\Models\Central\Module;
use App\Models\Central\ModuleBundle;
use App\Models\Central\Package;
use App\Models\Central\PricingSetting;
use App\Models\Tenant;

class TenantSubscription
{
    public function __construct(private Tenant $tenant) {}

    public function monthlyTotalCents(): int
    {
        return max(0, $this->beforeDiscountCents() - $this->discountCents() - $this->couponDiscountCents());
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

        if (! $percent || ! $until || now()->startOfDay()->gt(\Carbon\Carbon::parse($until))) {
            return 0;
        }

        return (int) round($this->beforeDiscountCents() * $percent / 100);
    }

    /** Wat de reseller deze maand verdient aan deze klant. */
    public function commissionCents(): int
    {
        if (! $this->tenant->reseller_id) {
            return 0;
        }

        $percent = (int) (\App\Models\Central\Reseller::on('central')
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

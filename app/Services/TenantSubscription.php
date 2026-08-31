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

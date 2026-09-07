<?php

namespace Tests\Concerns;

use App\Models\Central\LandlordUser;
use App\Models\Tenant;
use Illuminate\Support\Str;

/**
 * De twee rijen die elke test van het beheerpaneel nodig heeft.
 *
 * Ze stonden in elk testbestand opnieuw, elke keer net anders opgeschreven.
 */
trait MakesLandlordData
{
    private int $tenants_made = 0;

    protected function landlord(string $email = 'beheer@majorlabel.nl'): LandlordUser
    {
        return LandlordUser::on('central')->firstOrCreate(
            ['email' => $email],
            ['name' => 'Beheer', 'password' => 'geheim'],
        );
    }

    /**
     * Alleen de rij, zonder de gebeurtenissen eromheen: een klant aanmaken zet
     * normaal een database en een login klaar, en daar gaan deze tests niet
     * over. Met die gebeurtenissen erbij struikelen ze bovendien over de
     * database van een vorige ronde.
     */
    protected function tenantRow(array $attributes = []): Tenant
    {
        $this->tenants_made++;

        return Tenant::withoutEvents(fn () => Tenant::on('central')->create([
            'id' => 'test-' . $this->tenants_made . '-' . Str::lower(Str::random(6)),
            'name' => 'Testklant ' . $this->tenants_made,
            'tenancy_db_name' => 'lavoro_test_tenant_test',
            'package_key' => 'starter',
            'billing_period' => 'monthly',
            'storage_limit_gb' => 50,
            ...$attributes,
        ]));
    }
}
